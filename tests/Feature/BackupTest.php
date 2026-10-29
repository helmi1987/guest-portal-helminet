<?php

namespace Tests\Feature;

use App\Backup\BackupManager;
use App\Models\AuditLog;
use App\Models\LocalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * M6: backups (SQLite snapshot + env file + certificates as tar.gz), admin page, retention.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/portal-backup-test-'.getmypid().'-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($this->tmp.'/certs');
        $db = new PDO('sqlite:'.$this->tmp.'/source.sqlite');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec("CREATE TABLE t (v TEXT); INSERT INTO t VALUES ('kept')");
        file_put_contents($this->tmp.'/.env', "APP_KEY=base64:dGVzdA==\n");
        file_put_contents($this->tmp.'/certs/portal.crt', "CERT\n");
        file_put_contents($this->tmp.'/certs/.tmp-x', 'temp');
        config(['portal.backup' => ['dir' => $this->tmp.'/backups', 'keep' => 14, 'env_file' => $this->tmp.'/.env',
            'certs_dir' => $this->tmp.'/certs', 'database' => $this->tmp.'/source.sqlite']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_admin_creates_downloads_and_deletes_backups(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs(User::factory()->create())->get('/admin/backups')->assertForbidden();

        $this->onZone('admin')->actingAs($admin)->post('/admin/backups')->assertRedirect()->assertSessionHasNoErrors();
        [$backup] = app(BackupManager::class)->all();
        $path = $this->tmp.'/backups/'.$backup['name'];
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $entries = $this->untar($path);
        $this->assertSame(['db/database.sqlite', '.env', 'certs/portal.crt', 'backup-manifest.json'], array_keys($entries));
        $this->assertSame("APP_KEY=base64:dGVzdA==\n", $entries['.env']);
        file_put_contents($this->tmp.'/restored.sqlite', $entries['db/database.sqlite']);
        $this->assertSame('kept', (new PDO('sqlite:'.$this->tmp.'/restored.sqlite'))->query('SELECT v FROM t')->fetchColumn());
        $this->assertSame([], json_decode($entries['backup-manifest.json'], true)['skipped']);

        $this->onZone('admin')->actingAs($admin)->get('/admin/backups')->assertOk()->assertSee($backup['name']);
        $download = $this->onZone('admin')->actingAs($admin)->get('/admin/backups/'.$backup['name']);
        $download->assertOk()->assertDownload($backup['name']);
        $this->onZone('admin')->actingAs($admin)->get('/admin/backups/portal-backup-20200101-000000.tar.gz')->assertNotFound();

        $this->onZone('admin')->actingAs($admin)->delete('/admin/backups/'.$backup['name'])->assertRedirect();
        $this->assertFileDoesNotExist($path);
        $this->assertSame(['backup.created', 'backup.downloaded', 'backup.deleted'],
            AuditLog::where('action', 'like', 'backup.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_command_applies_retention(): void
    {
        File::ensureDirectoryExists($this->tmp.'/backups');
        foreach (['20200101-000000', '20200102-000000', '20200103-000000'] as $stamp) {
            touch($this->tmp."/backups/portal-backup-$stamp.tar.gz");
        }
        touch($this->tmp.'/backups/unrelated.txt');
        LocalSetting::put(BackupManager::KEEP_SETTING, 2);

        $this->assertSame(0, Artisan::call('portal:backup'));
        $names = array_column(app(BackupManager::class)->all(), 'name');
        $this->assertCount(2, $names);
        $this->assertSame('portal-backup-20200103-000000.tar.gz', $names[1]);
        $this->assertFileExists($this->tmp.'/backups/unrelated.txt');
        $this->assertSame(2, count(AuditLog::where('action', 'backup.created')->sole()->data['pruned']));
    }

    public function test_unsafe_names_are_skipped_failures_audited_and_sorting_uses_counter(): void
    {
        file_put_contents($this->tmp.'/certs/bad name.crt', 'x');
        file_put_contents($this->tmp.'/certs/a..b.pem', 'x');
        symlink($this->tmp.'/.env', $this->tmp.'/certs/link.pem');
        $backup = app(BackupManager::class)->create();
        $entries = $this->untar($this->tmp.'/backups/'.$backup['name']);
        $this->assertSame(['db/database.sqlite', '.env', 'certs/portal.crt', 'backup-manifest.json'], array_keys($entries));
        $this->assertEqualsCanonicalizing(['certs/bad name.crt', 'certs/a..b.pem', 'certs/link.pem'],
            json_decode($entries['backup-manifest.json'], true)['skipped']);
        unlink($this->tmp.'/backups/'.$backup['name']);

        foreach (['20200101-000000', '20200101-000000-2', '20200101-000000-10', '20200102-000000'] as $stamp) {
            touch($this->tmp."/backups/portal-backup-$stamp.tar.gz");
        }
        $this->assertSame(['20200102-000000', '20200101-000000-10', '20200101-000000-2', '20200101-000000'],
            array_map(fn ($b) => substr($b['name'], 14, -7), app(BackupManager::class)->all()));

        config(['portal.backup.database' => $this->tmp.'/missing.sqlite']);
        $this->assertSame(1, Artisan::call('portal:backup'));
        $this->assertStringContainsString('not found', AuditLog::where('action', 'backup.failed')->sole()->data['error']);
    }

    /** @return array<string, string> entry name => content */
    private function untar(string $path): array
    {
        $data = gzdecode((string) file_get_contents($path));
        $entries = [];
        for ($offset = 0; $offset + 512 <= strlen($data);) {
            $header = substr($data, $offset, 512);
            if (trim($header, "\0") === '') {
                break;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $size = octdec(trim(substr($header, 124, 12), "\0 "));
            $sum = 0;
            for ($i = 0; $i < 512; $i++) {
                $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
            }
            $this->assertSame($sum, octdec(trim(substr($header, 148, 8), "\0 ")), "checksum of $name");
            $entries[$name] = substr($data, $offset + 512, $size);
            $offset += 512 + (int) ceil($size / 512) * 512;
        }

        return $entries;
    }
}
