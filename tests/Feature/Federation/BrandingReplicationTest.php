<?php

namespace Tests\Feature\Federation;

use App\Branding\BrandingAssets;
use App\Branding\BrandingException;
use App\Branding\BrandingSettings;
use App\Federation\BrandingFiles;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Part I: branding files referenced by replicated settings are fetched by content hash from the
 * writer, then other peers (star: through the hub), checked like an upload.
 */
class BrandingReplicationTest extends TestCase
{
    use BuildsFederation, FederatedHttp, RefreshDatabase, SimulatesInstances;

    /** 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const SVG = '<?xml version="1.0"?><!-- c --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
        .'<rect width="10" height="10" fill="#123456"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFederation();
    }

    private function upload(string $bytes, string $kind): array
    {
        $path = tempnam(sys_get_temp_dir(), 'brand');
        file_put_contents($path, $bytes);
        try {
            return app(BrandingAssets::class)->store($path, $kind);
        } finally {
            @unlink($path);
        }
    }

    private function hasFile(string $name, array $asset): bool
    {
        return $this->on($name, fn () => app(BrandingAssets::class)->exists($asset));
    }

    public function test_files_follow_the_references_through_the_hub(): void
    {
        [$logo, $favicon] = $this->on('a', function () {
            $logo = $this->upload(base64_decode(self::PNG), BrandingAssets::LOGO);
            $favicon = $this->upload(self::SVG, BrandingAssets::FAVICON);
            BrandingSettings::saveGlobal(['logo' => $logo, 'favicon' => $favicon]);

            return [$logo, $favicon];
        });
        // Sync queue in tests: the push after commit and the apply hooks already ran.

        $this->syncRound();
        foreach (['hub', 'b'] as $name) {
            $this->assertTrue($this->hasFile($name, $logo), "logo on $name");
            $this->assertTrue($this->hasFile($name, $favicon), "sanitized SVG favicon on $name");
            $this->assertSame([], $this->on($name, fn () => app(BrandingFiles::class)->missing()));
        }
        $this->assertSame(
            $this->on('a', fn () => file_get_contents(app(BrandingAssets::class)->path(BrandingAssets::fileName($favicon)))),
            $this->on('b', fn () => file_get_contents(app(BrandingAssets::class)->path(BrandingAssets::fileName($favicon)))));

        // Per-site override written on b reaches a (b -> hub -> a).
        $siteLogo = $this->on('b', function () {
            $site = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']);
            // A different (2x1) PNG.
            $img = imagecreatetruecolor(2, 1);
            ob_start();
            imagepng($img);
            $asset = $this->upload((string) ob_get_clean(), BrandingAssets::LOGO);
            BrandingSettings::saveSite($site, ['logo' => $asset]);

            return $asset;
        });
        $this->syncRound();
        $this->assertTrue($this->hasFile('a', $siteLogo));
    }

    public function test_a_file_nobody_has_is_retried_later_and_paused_peers_get_nothing(): void
    {
        $asset = ['hash' => str_repeat('ab', 32), 'ext' => 'png'];
        $this->on('a', fn () => BrandingSettings::saveGlobal(['logo' => $asset]));
        $this->syncRound();
        $this->assertFalse($this->hasFile('b', $asset));
        $file = BrandingAssets::fileName($asset);
        $this->assertSame([$file => false], $this->on('b', fn () => app(BrandingFiles::class)->fetchMissing(force: true)));
        // Not asked again before the retry interval.
        $this->assertSame([], $this->on('b', fn () => app(BrandingFiles::class)->fetchMissing()));
        Cache::flush();
        $this->assertSame([$file => false], $this->on('b', fn () => app(BrandingFiles::class)->fetchMissing()));
    }

    public function test_import_checks_hash_type_size_and_sanitizing(): void
    {
        $this->on('b', function () {
            $assets = app(BrandingAssets::class);
            $png = base64_decode(self::PNG);
            $hash = hash('sha256', $png);
            $reasons = [];
            foreach ([
                'wrong hash' => [str_repeat('0', 64).'.png', $png, ['logo']],
                'wrong extension' => ["$hash.webp", $png, ['logo']],
                'not an image' => [hash('sha256', 'text').'.png', 'text', ['logo']],
                // An SVG that is not the sanitized form the sender stores (comment, XML declaration).
                'unsanitized svg' => [hash('sha256', self::SVG).'.svg', self::SVG, ['favicon']],
                'malformed name' => ['../x.png', $png, ['logo']],
            ] as $case => [$file, $bytes, $kinds]) {
                try {
                    $assets->import($file, $bytes, $kinds);
                    $this->fail("$case accepted");
                } catch (BrandingException $e) {
                    $reasons[$case] = $e->reason;
                }
            }
            $this->assertSame(['wrong hash' => 'hash_mismatch', 'wrong extension' => 'hash_mismatch',
                'not an image' => 'invalid_type', 'unsanitized svg' => 'hash_mismatch', 'malformed name' => 'invalid_type'], $reasons);

            config(['portal.branding.logo_max_bytes' => 10]);
            try {
                $assets->import("$hash.png", $png, ['logo']);
                $this->fail('oversize accepted');
            } catch (BrandingException $e) {
                $this->assertSame('too_large', $e->reason);
            }
            config(['portal.branding.logo_max_bytes' => 512 * 1024]);
            $assets->import("$hash.png", $png, ['logo']);
            $this->assertNotNull($assets->path("$hash.png"));
        });
    }
}
