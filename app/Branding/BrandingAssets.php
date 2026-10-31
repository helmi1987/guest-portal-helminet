<?php

namespace App\Branding;

use finfo;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Logo and favicon files (docs/branding.md): validated by content (getimagesize / finfo / SVG
 * parser, never the file name), SVG sanitized, stored as <sha256>.<ext> in the branding folder of
 * the data directory (part of the backups). Settings reference a file by hash + extension, so the
 * same file is stored once and URLs change with the content (long browser caching).
 * Files are local per instance; replication carries only the reference, a missing file is fetched
 * from another instance (App\Federation\BrandingFiles, CONCEPT.md §10.3).
 */
final class BrandingAssets
{
    public const LOGO = 'logo';

    public const FAVICON = 'favicon';

    /** Allowed types per kind (extension => MIME type served). */
    public const TYPES = [
        self::LOGO => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'],
        self::FAVICON => ['png' => 'image/png', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'],
    ];

    /** Unreferenced files younger than this (seconds) are kept by prune(). */
    public const PRUNE_GRACE = 300;

    public const FILE_PATTERN = '/^([a-f0-9]{64})\.(png|jpg|webp|svg|ico)$/';

    /** MIME types finfo may report per raster type. */
    private const FINFO = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon', 'image/ico'],
    ];

    public function dir(): string
    {
        return rtrim((string) config('portal.branding.dir'), '/');
    }

    /**
     * Validates and stores an upload; returns the reference kept in the settings.
     *
     * @return array{hash: string, ext: string}
     *
     * @throws BrandingException
     */
    public function store(string $path, string $kind): array
    {
        $max = (int) config($kind === self::LOGO ? 'portal.branding.logo_max_bytes' : 'portal.branding.favicon_max_bytes');
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new BrandingException('invalid_type');
        }
        if ($size > $max) {
            throw new BrandingException('too_large');
        }
        $bytes = (string) file_get_contents($path);
        [$ext, $bytes] = $this->detect($bytes, $kind);
        if (strlen($bytes) > $max) {
            throw new BrandingException('too_large');
        }

        $hash = hash('sha256', $bytes);
        $this->write("$hash.$ext", $bytes);

        return ['hash' => $hash, 'ext' => $ext];
    }

    /**
     * Stores a file received from another instance (CONCEPT.md §10.3) under its referenced name, after
     * the same checks as an upload: size limit and type detection of a kind referencing it, and the
     * content (after detection, i.e. sanitized SVG) must match the hash of the name.
     *
     * @param  list<string>  $kinds  kinds (logo, favicon) the file is referenced as
     *
     * @throws BrandingException
     */
    public function import(string $file, string $bytes, array $kinds): void
    {
        if (! preg_match(self::FILE_PATTERN, $file, $m)) {
            throw new BrandingException('invalid_type');
        }
        if (hash('sha256', $bytes) !== $m[1]) {
            throw new BrandingException('hash_mismatch');
        }
        $error = 'invalid_type';
        foreach ($kinds as $kind) {
            if (! isset(self::TYPES[$kind])) {
                continue;
            }
            $max = (int) config($kind === self::LOGO ? 'portal.branding.logo_max_bytes' : 'portal.branding.favicon_max_bytes');
            if ($bytes === '' || strlen($bytes) > $max) {
                $error = 'too_large';

                continue;
            }
            try {
                [$ext, $checked] = $this->detect($bytes, $kind);
            } catch (BrandingException $e) {
                $error = $e->getMessage();

                continue;
            }
            // The sender stored the checked (sanitized) bytes: they must come back unchanged.
            if ($ext !== $m[2] || $checked !== $bytes) {
                $error = 'hash_mismatch';

                continue;
            }
            $this->write($file, $checked);

            return;
        }
        throw new BrandingException($error);
    }

    /** Absolute path of a stored file, null if the name is malformed or the file is missing locally. */
    public function path(string $file): ?string
    {
        if (! preg_match(self::FILE_PATTERN, $file)) {
            return null;
        }
        $path = $this->dir().'/'.$file;

        return is_file($path) ? $path : null;
    }

    /** True if the referenced file exists on this instance. */
    public function exists(?array $asset): bool
    {
        return self::valid($asset) && $this->path(self::fileName($asset)) !== null;
    }

    public static function valid(?array $asset): bool
    {
        return is_array($asset) && is_string($asset['hash'] ?? null) && is_string($asset['ext'] ?? null)
            && preg_match(self::FILE_PATTERN, self::fileName($asset)) === 1;
    }

    public static function fileName(array $asset): string
    {
        return $asset['hash'].'.'.$asset['ext'];
    }

    public static function mime(string $ext): string
    {
        return self::TYPES[self::LOGO][$ext] ?? self::TYPES[self::FAVICON][$ext] ?? 'application/octet-stream';
    }

    /**
     * Deletes stored files no setting references any more (older than PRUNE_GRACE).
     *
     * @param  list<string>  $keep  file names still referenced
     * @return list<string> deleted file names
     */
    public function prune(array $keep): array
    {
        $dir = $this->dir();
        $deleted = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $file) {
            // Grace period: a file uploaded by a concurrent request may not be referenced yet.
            if (preg_match(self::FILE_PATTERN, $file) && ! in_array($file, $keep, true)
                && (int) @filemtime("$dir/$file") < now()->getTimestamp() - self::PRUNE_GRACE && @unlink("$dir/$file")) {
                $deleted[] = $file;
            }
        }
        if ($deleted !== []) {
            Log::info('Branding: unused files deleted', ['files' => $deleted]);
        }

        return $deleted;
    }

    /** Writes a file atomically (no-op when it exists: the name is its content hash). */
    private function write(string $file, string $bytes): void
    {
        $dir = $this->dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Branding folder cannot be created.');
        }
        $target = "$dir/$file";
        if (! is_file($target)) {
            $tmp = "$dir/.tmp-".bin2hex(random_bytes(6));
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || ! @chmod($tmp, 0644) || ! rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('Branding file cannot be written.');
            }
        }
    }

    /**
     * @return array{0: string, 1: string} extension and the bytes to store (sanitized for SVG)
     *
     * @throws BrandingException
     */
    private function detect(string $bytes, string $kind): array
    {
        $allowed = self::TYPES[$kind];
        $info = @getimagesizefromstring($bytes);
        if ($info !== false) {
            $ext = match ($info[2]) {
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_WEBP => 'webp',
                IMAGETYPE_ICO => 'ico',
                default => null,
            };
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if ($ext === null || ! isset($allowed[$ext]) || ! in_array($mime, self::FINFO[$ext], true)) {
                throw new BrandingException('invalid_type');
            }
            $limit = (int) config('portal.branding.max_pixels');
            if ($info[0] < 1 || $info[1] < 1 || $info[0] > $limit || $info[1] > $limit) {
                throw new BrandingException('dimensions');
            }

            return [$ext, $bytes];
        }

        // Not a raster image: only SVG is left (an XML document with an <svg> root).
        if (! isset($allowed['svg']) || ! preg_match('/^(\xEF\xBB\xBF)?\s*</', $bytes)) {
            throw new BrandingException('invalid_type');
        }

        return ['svg', SvgSanitizer::sanitize($bytes)];
    }
}
