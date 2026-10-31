<?php

namespace App\Backup;

use App\Models\LocalSetting;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backups per instance (CONCEPT.md §9): one tar.gz with a consistent SQLite snapshot (VACUUM INTO on
 * a separate connection, safe with WAL), the env file (APP_KEY decrypts the stored secrets), the
 * certificate folder, the branding files (logo/favicon) and the federation key and certificate. Archives contain secrets: mode 0600,
 * folder outside the web root. Entry paths mirror the data folder (db/database.sqlite, .env,
 * certs/..., branding/..., federation/...), so a restore is an extract into the stopped container's data folder.
 */
class BackupManager
{
    public const KEEP_SETTING = 'backup_keep';

    public const NAME_PATTERN = '/^portal-backup-\d{8}-\d{6}(-\d+)?\.tar\.gz$/';

    public const MANIFEST = 'backup-manifest.json';

    private const ENTRY_PATTERN = '/^[A-Za-z0-9._-]{1,94}$/';

    public function dir(): string
    {
        return rtrim((string) config('portal.backup.dir'), '/');
    }

    public static function keep(): int
    {
        return max(1, (int) LocalSetting::get(self::KEEP_SETTING, config('portal.backup.keep')));
    }

    /**
     * Creates a backup and returns its file name and the files that could not be read.
     *
     * @return array{name: string, size: int, skipped: list<string>}
     */
    public function create(): array
    {
        $dir = $this->dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Backup folder cannot be created.');
        }
        @chmod($dir, 0700);

        // Local time of the display zone (readable for admins); sorting uses the name.
        $stamp = LocalTime::now()->format('Ymd-His');
        $name = "portal-backup-$stamp.tar.gz";
        for ($i = 2; file_exists("$dir/$name"); $i++) {
            $name = "portal-backup-$stamp-$i.tar.gz";
        }

        $token = bin2hex(random_bytes(6));
        $snapshot = "$dir/.tmp-$token.sqlite";
        $archive = "$dir/.tmp-$token.tar.gz";
        $skipped = [];
        // Files created below (snapshot, archive) are private: 0600.
        $umask = umask(0077);
        try {
            $this->snapshotDatabase($snapshot);

            $tar = new TarGzWriter($archive);
            $files = ['db/database.sqlite'];
            $tar->addFile('db/database.sqlite', $snapshot);

            $env = (string) config('portal.backup.env_file');
            if (is_file($env) && is_readable($env)) {
                $tar->addFile('.env', $env);
                $files[] = '.env';
            } else {
                $skipped[] = '.env';
            }

            $this->addFolder($tar, 'certs', (string) config('portal.backup.certs_dir'), $files, $skipped);
            $this->addFolder($tar, 'branding', (string) config('portal.branding.dir'), $files, $skipped);
            // Federation key and certificate: a restored instance keeps its pin (peers trust it).
            $this->addFolder($tar, 'federation', (string) config('portal.federation.dir'), $files, $skipped);

            $tar->addString(self::MANIFEST, json_encode([
                'created_at' => now()->toIso8601String(),
                'version' => config('portal.version'),
                'instance' => config('portal.instance_name'),
                'files' => $files,
                'skipped' => $skipped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $tar->close();
            @chmod($archive, 0600);

            if (! rename($archive, "$dir/$name")) {
                throw new RuntimeException('Backup file cannot be moved into place.');
            }
        } finally {
            umask($umask);
            foreach ([$snapshot, $archive, "$snapshot-journal"] as $tmp) {
                if (file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        if ($skipped !== []) {
            Log::warning('Backup: files not readable, skipped', ['files' => $skipped]);
        }
        Log::info('Backup created', ['file' => $name]);

        return ['name' => $name, 'size' => (int) filesize("$dir/$name"), 'skipped' => $skipped];
    }

    /** @return list<array{name: string, size: int, created_at: int}> newest first */
    public function all(): array
    {
        $dir = $this->dir();
        $out = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $file) {
            if (preg_match(self::NAME_PATTERN, $file) && is_file("$dir/$file")) {
                $out[] = ['name' => $file, 'size' => (int) filesize("$dir/$file"), 'created_at' => (int) filemtime("$dir/$file")];
            }
        }
        // Newest first: UTC timestamp, then counter (no counter = 1).
        usort($out, fn ($a, $b) => self::sortKey($b['name']) <=> self::sortKey($a['name']));

        return $out;
    }

    /** @return array{0: string, 1: int} timestamp, counter */
    private static function sortKey(string $name): array
    {
        preg_match('/^portal-backup-(\d{8}-\d{6})(?:-(\d+))?\.tar\.gz$/', $name, $m);

        return [$m[1] ?? '', (int) ($m[2] ?? 1)];
    }

    /**
     * Problem with the backup folder permissions (should be 0700 and owned by the app user), null if fine.
     *
     * @return array{mode: string, owner: string}|null
     */
    public function insecureDir(): ?array
    {
        $dir = $this->dir();
        if (! is_dir($dir)) {
            return null;
        }
        clearstatcache(true, $dir);
        $mode = fileperms($dir) & 0777;
        $owner = (int) fileowner($dir);
        $ownOk = ! function_exists('posix_geteuid') || $owner === posix_geteuid();
        if ($mode === 0700 && $ownOk) {
            return null;
        }
        $name = function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? (string) $owner) : (string) $owner;

        return ['mode' => sprintf('%04o', $mode), 'owner' => $name];
    }

    /** Absolute path of an existing backup, null for unknown or malformed names. */
    public function path(string $name): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }
        $path = $this->dir().'/'.$name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path !== null && @unlink($path);
    }

    /** Deletes the oldest backups beyond the retention count; returns the deleted names. */
    public function prune(?int $keep = null): array
    {
        $deleted = [];
        foreach (array_slice($this->all(), $keep ?? self::keep()) as $backup) {
            if ($this->delete($backup['name'])) {
                $deleted[] = $backup['name'];
            }
        }

        return $deleted;
    }

    /**
     * Adds the plain files of a data subfolder (no recursion) as "<prefix>/<name>".
     *
     * @param  list<string>  $files
     * @param  list<string>  $skipped
     */
    private function addFolder(TarGzWriter $tar, string $prefix, string $dir, array &$files, array &$skipped): void
    {
        $dir = rtrim($dir, '/');
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $file) {
            $path = "$dir/$file";
            // Dot files: ".", ".." and temp files (certificate pull, branding upload).
            if (str_starts_with($file, '.') || (! is_file($path) && ! is_link($path))) {
                continue;
            }
            // Only plain, safe names (ustar name field incl. the prefix); no symlinks.
            if (! preg_match(self::ENTRY_PATTERN, $file) || strlen("$prefix/$file") > 100 || str_contains($file, '..') || is_link($path)) {
                $skipped[] = "$prefix/".mb_scrub($file);

                continue;
            }
            if (is_readable($path)) {
                $tar->addFile("$prefix/$file", $path);
                $files[] = "$prefix/$file";
            } else {
                $skipped[] = "$prefix/$file";
            }
        }
    }

    private function snapshotDatabase(string $target): void
    {
        $database = config('portal.backup.database');
        if ($database === null) {
            if (config('database.connections.'.config('database.default').'.driver') !== 'sqlite') {
                throw new RuntimeException('Backups support SQLite only.');
            }
            $database = config('database.connections.'.config('database.default').'.database');
        }
        if (! is_string($database) || $database === ':memory:' || ! is_file($database)) {
            throw new RuntimeException('SQLite database file not found.');
        }

        try {
            // Own connection: VACUUM cannot run inside the application's (test) transaction.
            $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('VACUUM INTO '.$pdo->quote($target));
            $pdo = null;
        } catch (Throwable $e) {
            throw new RuntimeException('Database snapshot failed: '.$e->getMessage());
        }
        @chmod($target, 0600);
    }
}
