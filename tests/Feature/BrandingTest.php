<?php

namespace Tests\Feature;

use App\Branding\Branding;
use App\Branding\BrandingAssets;
use App\Branding\BrandingSettings;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v0.10.0: branding (logo, favicon, colours, custom CSS; global + per-site override; gp-* hooks).
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const SVG = '<?xml version="1.0"?><!-- editor comment --><svg xmlns="http://www.w3.org/2000/svg" '
        .'xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:version="1.3" viewBox="0 0 10 10">'
        .'<defs><linearGradient id="g"><stop offset="0" stop-color="#123456"/></linearGradient></defs>'
        .'<rect width="10" height="10" fill="url(#g)"/><animate attributeName="x" to="5"/></svg>';

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::create(['slug' => 'Site-A', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('portal.branding.dir'));
        parent::tearDown();
    }

    public function test_guest_page_has_hook_classes_and_default_logo_without_upload(): void
    {
        $page = $this->enterPortal()->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<body class="gp-page gp-guest gp-site-site-a gp-step-portal /', $html);
        foreach (['gp-container', 'gp-header', 'gp-logo', 'gp-logo-default', 'gp-title', 'gp-lang-switch', 'gp-lang-link',
            'gp-lang-active', 'gp-main', 'gp-card', 'gp-card-title', 'gp-intro', 'gp-form', 'gp-label', 'gp-input',
            'gp-code-input', 'gp-checkbox', 'gp-button'] as $class) {
            $this->assertMatchesRegularExpression('/class="[^"]*\b'.$class.'\b/', $html, $class);
        }
        // Built-in default logo (inline SVG) and favicons, no theme stylesheet when nothing is configured.
        $this->assertStringContainsString('<svg class="gp-logo gp-logo-default', $html);
        $this->assertStringContainsString('href="/favicon.svg"', $html);
        $this->assertStringNotContainsString('/branding/theme/', $html);

        // Error page without session: global branding, step + variant classes.
        $this->flushSession();
        $error = $this->onZone('guest')->get('/portal')->getContent();
        $this->assertStringContainsString('gp-step-error gp-step-error-no_hotspot', $error);
        $this->assertStringNotContainsString('gp-site-', $error);
    }

    public function test_admin_uploads_png_logo_served_with_strict_headers(): void
    {
        $this->save('/admin/settings/branding', ['logo' => $this->png()])->assertRedirect()->assertSessionHasNoErrors();

        $logo = BrandingSettings::global()['logo'];
        $this->assertSame('png', $logo['ext']);
        $this->assertSame(hash('sha256', base64_decode(self::PNG)), $logo['hash']);
        $this->assertFileExists(config('portal.branding.dir').'/'.$logo['hash'].'.png');
        $this->assertSame(['changed' => ['logo']], AuditLog::where('action', 'settings.branding')->sole()->data);

        $url = '/branding/files/'.$logo['hash'].'.png';
        $html = $this->enterPortal()->getContent();
        $this->assertStringContainsString('<img src="'.$url.'"', $html);
        $this->assertStringNotContainsString('gp-logo-default', $html);
        $this->onZone('admin')->actingAs($this->admin)->get('/admin')->assertSee($url);

        $file = $this->onZone('guest')->get($url)->assertOk();
        $file->assertHeader('Content-Type', 'image/png');
        $file->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith("default-src 'none'; style-src 'unsafe-inline'", $file->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('immutable', $file->headers->get('Cache-Control'));
        $this->onZone('admin')->get($url)->assertOk();
        $this->onZone('internal')->get($url)->assertNotFound();
        $this->onZone('guest')->get('/branding/files/'.str_repeat('0', 64).'.png')->assertNotFound();

        // Remove: default logo again; the unreferenced file is kept during the grace period, then deleted.
        $path = config('portal.branding.dir').'/'.$logo['hash'].'.png';
        $this->save('/admin/settings/branding', ['logo_remove' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(BrandingSettings::global()['logo']);
        $this->assertFileExists($path);
        touch($path, time() - BrandingAssets::PRUNE_GRACE - 1);
        $this->save('/admin/settings/branding', [])->assertSessionHasNoErrors();
        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString('gp-logo-default', $this->enterPortal()->getContent());
    }

    public function test_svg_logo_is_sanitized_and_favicon_accepted(): void
    {
        $this->save('/admin/settings/branding', [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', self::SVG),
            'favicon' => $this->png('icon.png'),
        ])->assertSessionHasNoErrors();

        $logo = BrandingSettings::global()['logo'];
        $this->assertSame('svg', $logo['ext']);
        $stored = (string) file_get_contents(config('portal.branding.dir').'/'.$logo['hash'].'.svg');
        $this->assertStringNotContainsString('editor comment', $stored);
        $this->assertStringNotContainsString('inkscape:version', $stored);
        $this->assertStringNotContainsString('<animate', $stored);
        $this->assertStringContainsString('fill="url(#g)"', $stored);

        $this->onZone('guest')->get('/branding/files/'.$logo['hash'].'.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $favicon = BrandingSettings::global()['favicon'];
        $this->assertStringContainsString('<link rel="icon" href="/branding/files/'.$favicon['hash'].'.png" type="image/png">',
            $this->enterPortal()->getContent());
    }

    public function test_rejects_oversize_wrong_type_and_unsafe_svg(): void
    {
        $cases = [
            'oversize' => UploadedFile::fake()->create('big.png', 600, 'image/png'),
            'text' => UploadedFile::fake()->createWithContent('logo.png', 'not an image'),
            'gif' => UploadedFile::fake()->createWithContent('logo.png', base64_decode('R0lGODlhAQABAAAAACw=')),
            'script' => $this->svg('<script>alert(1)</script>'),
            'handler' => $this->svg('<rect width="1" height="1" onload="alert(1)"/>'),
            'handler_caps' => $this->svg('<rect width="1" height="1" ONCLICK="alert(1)"/>'),
            'foreign' => $this->svg('<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'),
            'external_href' => $this->svg('<image href="https://tracker.example.com/x.png" width="1" height="1"/>'),
            'xlink_js' => $this->svg('<use xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="java&#x09;script:alert(1)"/>'),
            'css_import' => $this->svg('<style>@import url(https://example.com/a.css);</style>'),
            'css_url' => $this->svg('<rect width="1" height="1" style="fill: url(https://example.com/a.svg)"/>'),
            'entity' => UploadedFile::fake()->createWithContent('logo.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]>'
                .'<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'),
            'utf16_bom' => UploadedFile::fake()->createWithContent('logo.svg', "\xFF\xFE".mb_convert_encoding(
                '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>', 'UTF-16LE', 'UTF-8')),
            'utf16_no_bom' => UploadedFile::fake()->createWithContent('logo.svg', mb_convert_encoding(
                '<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>', 'UTF-16LE', 'UTF-8')),
            'utf7' => UploadedFile::fake()->createWithContent('logo.svg', '<?xml version="1.0" encoding="UTF-7"?>'
                .'+ADw-!DOCTYPE svg+AD4-<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'not_svg_root' => UploadedFile::fake()->createWithContent('logo.svg', '<html><body>x</body></html>'),
        ];
        foreach ($cases as $case => $file) {
            $this->save('/admin/settings/branding', ['logo' => $file])->assertSessionHasErrors('logo');
            $this->assertNull(BrandingSettings::global()['logo'], $case);
        }
        $this->assertSame([], glob(config('portal.branding.dir').'/*') ?: []);
        // JPEG is not a favicon type.
        $this->save('/admin/settings/branding', ['favicon' => UploadedFile::fake()->createWithContent('f.jpg', "\xFF\xD8\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xD9")])
            ->assertSessionHasErrors('favicon');
    }

    public function test_manager_cannot_change_branding(): void
    {
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->post('/admin/settings/branding', ['accent' => '#ff0000'])->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->post('/admin/sites/'.$this->site->id.'/branding', ['override' => ['accent' => '1'],
            'accent' => '#ff0000'])->assertForbidden();
        $this->assertNull(BrandingSettings::global()['accent']);
        $this->assertSame([], BrandingSettings::siteOverrides($this->site->fresh()));
    }

    public function test_theme_stylesheet_with_site_override_and_global_fallback(): void
    {
        $this->save('/admin/settings/branding', ['accent' => '#FDE047', 'background' => '#abc', 'text' => '',
            'css' => ".gp-title { display: none; }\nbody { background-image: url(data:image/png;base64,AAAA); }"])
            ->assertSessionHasNoErrors();

        $html = $this->enterPortal()->getContent();
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="(/branding/theme/global\.css\?v=[0-9a-f]{16})">#', $html);
        preg_match('#href="(/branding/theme/global\.css\?v=[0-9a-f]{16})"#', $html, $m);
        $css = $this->onZone('guest')->get($m[1])->assertOk();
        $this->assertStringStartsWith('text/css', $css->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $css->headers->get('Cache-Control'));
        $body = $css->getContent();
        $this->assertStringContainsString('--gp-accent: #fde047;', $body);
        $this->assertStringContainsString('--gp-accent-contrast: #000000;', $body);
        $this->assertStringContainsString('--gp-bg: #aabbcc;', $body);
        $this->assertStringNotContainsString('--gp-text:', $body);
        // A custom background derives the card, input and border colours from background and text.
        $this->assertStringContainsString('--gp-surface: color-mix(in srgb, var(--gp-bg) 92%, var(--gp-text));', $body);
        $this->assertStringContainsString('--gp-input-bg: var(--gp-bg);', $body);
        $this->assertStringContainsString('--gp-border: color-mix(', $body);
        $this->assertStringContainsString('.gp-title { display: none; }', $body);
        $this->assertStringContainsString('no-cache', $this->onZone('guest')->get('/branding/theme/global.css?v=old')->headers->get('Cache-Control'));

        // Site override of the accent colour and CSS: own stylesheet; logo/favicon inherited.
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['accent' => '1', 'css' => '1', 'logo' => '0'],
            'accent' => '#1d4ed8', 'css' => '.gp-card { border-radius: 1rem; }'])->assertSessionHasNoErrors();
        $this->assertSame(['accent' => '#1d4ed8', 'css' => '.gp-card { border-radius: 1rem; }'], BrandingSettings::siteOverrides($this->site->fresh()));
        $this->assertSame(['slug' => 'Site-A', 'overrides' => ['accent', 'css'], 'changed' => ['accent', 'css']],
            AuditLog::where('action', 'site.branding')->sole()->data);

        $html = $this->enterPortal()->getContent();
        $this->assertStringContainsString('/branding/theme/'.$this->site->id.'.css?v=', $html);
        $site = $this->onZone('guest')->get('/branding/theme/'.$this->site->id.'.css')->assertOk()->getContent();
        $this->assertStringContainsString('--gp-accent: #1d4ed8;', $site);
        $this->assertStringContainsString('--gp-accent-contrast: #ffffff;', $site);
        $this->assertStringContainsString('--gp-bg: #aabbcc;', $site);
        $this->assertStringContainsString('.gp-card { border-radius: 1rem; }', $site);
        $this->assertStringNotContainsString('.gp-title', $site);
        // The admin UI always uses the global branding.
        $this->onZone('admin')->actingAs($this->admin)->get('/admin')->assertSee('/branding/theme/global.css', false)
            ->assertDontSee('/branding/theme/'.$this->site->id, false);
        $this->onZone('guest')->get('/branding/theme/01aaaaaaaaaaaaaaaaaaaaaaaa.css')->assertNotFound();

        // Back to inherit: the site uses the global stylesheet again.
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['accent' => '0', 'css' => '0']])
            ->assertSessionHasNoErrors();
        $this->assertSame([], BrandingSettings::siteOverrides($this->site->fresh()));
        $this->assertStringContainsString('/branding/theme/global.css?v=', $this->enterPortal()->getContent());
    }

    public function test_site_logo_override_inherit_and_keep(): void
    {
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['logo' => '1']])
            ->assertSessionHasErrors('logo');
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['logo' => '1'], 'logo' => $this->png()])
            ->assertSessionHasNoErrors();
        $logo = BrandingSettings::siteOverrides($this->site->fresh())['logo'];
        $this->assertStringContainsString('/branding/files/'.$logo['hash'].'.png', $this->enterPortal()->getContent());
        // Keeping the override without a new upload keeps the file.
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['logo' => '1']])->assertSessionHasNoErrors();
        $this->assertSame($logo, BrandingSettings::siteOverrides($this->site->fresh())['logo']);
        // Other pages (no site in the session) keep the default logo.
        $this->flushSession();
        $this->assertStringContainsString('gp-logo-default', $this->onZone('guest')->get('/')->getContent());
        $this->onZone('admin')->actingAs($this->admin)->get('/admin/sites/'.$this->site->id.'/edit')->assertOk()
            ->assertSee(__('portal.branding.site_title'));
    }

    public function test_custom_css_policy(): void
    {
        foreach ([
            '@import url("/x.css");' => 'css_import',
            '@\69mport "x.css";' => 'css_import',
            'body { background: url(https://example.com/x.png); }' => 'css_external',
            'body { background: u\72l(//example.com/x.png); }' => 'css_external',
            'body { background-image: image-set("https://example.com/x.png" 1x); }' => 'css_external',
            'body { width: expression(alert(1)); }' => 'css_unsafe',
            "a { background: image-set(\"ht\\\ntp://e.com/x.png\" 1x); }" => 'css_external',
            'a { background: image-set("\\1 https://e.com/x.png" 1x); }' => 'css_external',
            'a { background: image-set("ht\\9 tps://e.com/a.png" 1x); }' => 'css_external',
            'a { background: image-set("https:e.com/a.png" 1x); }' => 'css_external',
            'a { background: url(\\\\\\\\e.com/x.png); }' => 'css_external',
        ] as $css => $error) {
            $this->save('/admin/settings/branding', ['css' => $css])
                ->assertSessionHasErrors(['css' => __('portal.branding.errors.'.$error)]);
        }
        $this->save('/admin/settings/branding', ['css' => str_repeat('a', 65537)])->assertSessionHasErrors('css');
        $this->save('/admin/settings/branding', ['accent' => 'red'])->assertSessionHasErrors('accent');
        $this->save('/admin/settings/branding', ['css' => 'body { background: url("/img/bg.png") , url(data:image/svg+xml;base64,PHN2Zy8+); } '
            .'.gp-title::after { content: "Note: hi"; } '
            .'.gp-card { background: url(\'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg"/>\'); }'])->assertSessionHasNoErrors();
    }

    public function test_settings_page_shows_default_logo_preview(): void
    {
        $this->onZone('admin')->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.branding.title'))->assertSee(__('portal.branding.default_logo'))
            ->assertSee('gp-logo-default', false);
    }

    public function test_colour_scheme_defaults_to_dark_and_global_switch_to_light(): void
    {
        // Unset value (existing installs) = dark, on guest and admin pages (<html> and <body>).
        $this->assertSame('dark', BrandingSettings::global()['scheme']);
        $html = $this->enterPortal()->getContent();
        $this->assertStringContainsString('<html lang="en" class="gp-theme-dark">', $html);
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bgp-theme-dark\b/', $html);
        $this->onZone('admin')->actingAs($this->admin)->get('/admin')->assertSee('gp-theme-dark', false)
            ->assertDontSee('gp-theme-light', false);

        $this->save('/admin/settings/branding', ['scheme' => 'light'])->assertSessionHasNoErrors();
        $this->assertSame('light', BrandingSettings::global()['scheme']);
        $this->assertSame(['changed' => ['scheme']], AuditLog::where('action', 'settings.branding')->sole()->data);
        $html = $this->enterPortal()->getContent();
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bgp-theme-light\b/', $html);
        $this->assertStringNotContainsString('gp-theme-dark', $html);
        $this->onZone('admin')->actingAs($this->admin)->get('/admin')->assertSee('gp-theme-light', false);
        // The scheme is a class only; it does not create a theme stylesheet.
        $this->assertStringNotContainsString('/branding/theme/', $html);

        $this->save('/admin/settings/branding', ['scheme' => 'blue'])->assertSessionHasErrors('scheme');
        $this->assertSame('light', BrandingSettings::global()['scheme']);
    }

    public function test_site_scheme_override_inherit_and_admin_uses_global(): void
    {
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['scheme' => '1'], 'scheme' => 'light'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['scheme' => 'light'], BrandingSettings::siteOverrides($this->site->fresh()));
        $this->assertSame(['slug' => 'Site-A', 'overrides' => ['scheme'], 'changed' => ['scheme']],
            AuditLog::where('action', 'site.branding')->sole()->data);
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bgp-theme-light\b/', $this->enterPortal()->getContent());
        // Admin UI (even the edit page of that site) and pages without a site keep the global scheme.
        $this->onZone('admin')->actingAs($this->admin)->get('/admin/sites/'.$this->site->id.'/edit')->assertOk()
            ->assertSee('class="gp-theme-dark"', false)->assertDontSee('gp-theme-light"', false);
        $this->flushSession();
        $this->assertStringContainsString('gp-theme-dark', $this->onZone('guest')->get('/')->getContent());

        // Global light, site overrides dark.
        $this->save('/admin/settings/branding', ['scheme' => 'light'])->assertSessionHasNoErrors();
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['scheme' => '1'], 'scheme' => 'dark'])
            ->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bgp-theme-dark\b/', $this->enterPortal()->getContent());
        $this->onZone('admin')->actingAs($this->admin)->get('/admin')->assertSee('class="gp-theme-light"', false);

        // Inherit again: the key is removed and the global scheme applies.
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['scheme' => '0'], 'scheme' => 'dark'])
            ->assertSessionHasNoErrors();
        $this->assertSame([], BrandingSettings::siteOverrides($this->site->fresh()));
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bgp-theme-light\b/', $this->enterPortal()->getContent());
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['scheme' => '1']])->assertSessionHasErrors('scheme');
        // Only an accent colour: the scheme defaults of surface, inputs and borders stay.
        $accentOnly = new Branding(['logo' => null, 'favicon' => null, 'accent' => '#123456', 'background' => null, 'text' => null, 'css' => '']);
        $this->assertStringNotContainsString('--gp-surface', $accentOnly->css());

        // Without the override checkbox the scheme select is ignored, even with an invalid value.
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['scheme' => '0'], 'scheme' => 'blue'])
            ->assertSessionHasNoErrors();
        $this->save('/admin/sites/'.$this->site->id.'/branding', ['override' => ['scheme' => '1'], 'scheme' => 'blue'])
            ->assertSessionHasErrors('scheme');
    }

    public function test_manager_cannot_change_scheme(): void
    {
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->post('/admin/settings/branding', ['scheme' => 'light'])->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->post('/admin/sites/'.$this->site->id.'/branding',
            ['override' => ['scheme' => '1'], 'scheme' => 'light'])->assertForbidden();
        $this->assertSame('dark', BrandingSettings::global()['scheme']);
        $this->assertSame([], BrandingSettings::siteOverrides($this->site->fresh()));
        $this->assertSame(0, AuditLog::whereIn('action', ['settings.branding', 'site.branding'])->count());
    }

    private function save(string $uri, array $data = [])
    {
        return $this->onZone('admin')->actingAs($this->admin)->from('/admin/settings')->post($uri, $data);
    }

    private function enterPortal()
    {
        return $this->onZone('guest')->get('/portal?'.http_build_query([
            'site' => 'Site-A', 'mac' => 'AA:BB:CC:00:00:01', 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function svg(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">'.$body.'</svg>');
    }
}
