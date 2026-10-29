<?php

namespace Tests\Feature;

use App\Approval\ApprovalMessage;
use App\Approval\ApprovalSettings;
use App\Approval\PollBackoff;
use App\Auth\Oidc\OidcSettings;
use App\Backup\BackupManager;
use App\Enums\ExpiryMode;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * v0.10.0 fixes from the container test and review: display time zone, admin URL fallback, Telegram backoff.
 */
class OperationalFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_times_are_stored_in_utc_and_shown_and_entered_in_the_display_zone(): void
    {
        config(['portal.display_timezone' => 'Europe/Zurich']);
        $this->assertSame('UTC', config('app.timezone'));
        $admin = User::factory()->admin()->create();
        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]);

        // Admin enters 12:00 local (summer time, UTC+2): stored as 10:00 UTC, shown as 12:00.
        $this->onZone('admin')->actingAs($admin)->post("/admin/grants/{$grant->id}/expiry", ['expires_at' => '2030-07-01T12:00'])
            ->assertRedirect();
        $this->assertSame('2030-07-01 10:00:00', $grant->fresh()->getRawOriginal('expires_at'));
        $this->onZone('admin')->actingAs($admin)->get("/admin/grants/{$grant->id}/edit")
            ->assertSee('2030-07-01 12:00')->assertSee('value="2030-07-01T12:00"', false);

        // Notification text and backup file name use local time.
        $request = new ApprovalRequest(['expires_at' => Carbon::parse('2030-01-15 08:30:00', 'UTC')]);
        $this->assertContains(__('portal.approvals.notify.valid_until', ['time' => '09:30'], ApprovalSettings::locale()),
            ApprovalMessage::lines($request));

        $tmp = sys_get_temp_dir().'/portal-tz-test-'.getmypid();
        File::ensureDirectoryExists($tmp);
        $db = new \PDO('sqlite:'.$tmp.'/db.sqlite');
        $db->exec('CREATE TABLE t (v TEXT)');
        config(['portal.backup' => ['dir' => $tmp.'/backups', 'keep' => 14, 'env_file' => $tmp.'/.env',
            'certs_dir' => $tmp.'/certs', 'database' => $tmp.'/db.sqlite']]);
        Carbon::setTestNow(Carbon::parse('2030-01-15 23:30:00', 'UTC'));
        $this->assertSame('portal-backup-20300116-003000.tar.gz', app(BackupManager::class)->create()['name']);
        File::deleteDirectory($tmp);

        // Unknown zone names fall back to UTC.
        config(['portal.display_timezone' => 'Mars/Olympus']);
        $this->assertSame('UTC', LocalTime::zone());
    }

    public function test_audit_filter_and_terms_use_the_display_zone(): void
    {
        config(['portal.display_timezone' => 'Europe/Zurich']);
        $admin = User::factory()->admin()->create();
        // 23:30 UTC on the 15th is 00:30 local on the 16th.
        $log = AuditLog::create(['action' => 'settings.zone_probe']);
        $log->forceFill(['created_at' => Carbon::parse('2030-01-15 23:30:00', 'UTC')])->save();

        $this->onZone('admin')->actingAs($admin)->get('/admin/audit?from=2030-01-16&to=2030-01-16')->assertSee('settings.zone_probe')
            ->assertSee('16.01.2030 00:30:00');
        $this->onZone('admin')->actingAs($admin)->get('/admin/audit?to=2030-01-15')->assertDontSee('settings.zone_probe');

        TermsVersion::create(['locale' => 'de', 'number' => 1, 'body' => 'Text', 'published_at' => Carbon::parse('2030-01-15 23:30:00', 'UTC')]);
        $this->onZone('admin')->actingAs($admin)->get('/admin/terms')->assertSee('(2030-01-16 00:30)', false);
    }

    public function test_admin_url_falls_back_to_app_url_with_admin_port_and_settings_warn(): void
    {
        config(['app.url' => 'https://portal.example.com', 'portal.admin_port' => 8443]);
        $this->assertSame('https://portal.example.com:8443', ApprovalSettings::adminUrl());
        $this->assertSame('https://portal.example.com:8443/admin/oidc/callback', OidcSettings::redirectUri());
        config(['app.url' => 'https://portal.example.com:443/']);
        $this->assertSame('https://portal.example.com:8443', ApprovalSettings::adminUrl());

        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')
            ->assertSee(__('portal.settings.admin_url_missing', ['url' => 'https://portal.example.com:8443']));

        Setting::put('admin_url', 'https://admin.example.com');
        $this->assertSame('https://admin.example.com', ApprovalSettings::adminUrl());
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')
            ->assertDontSee(__('portal.settings.admin_url_missing', ['url' => 'https://portal.example.com:8443']));
    }

    public function test_telegram_polling_backs_off_exponentially_and_logs_once_per_step(): void
    {
        $backoff = new PollBackoff;
        Carbon::setTestNow('2030-01-01 00:00:00');
        $delays = [];
        for ($i = 0; $i < 7; $i++) {
            $this->assertTrue($backoff->ready('c'));
            $delays[] = $backoff->failed('c');
            $this->assertFalse($backoff->ready('c'));
            $this->assertSame(end($delays), $backoff->wait(['c']));
            Carbon::setTestNow(now()->addSeconds(end($delays)));
        }
        $this->assertSame([10, 20, 40, 80, 160, 300, 300], $delays);
        $this->assertSame(7, $backoff->succeeded('c'));
        $this->assertSame(0, $backoff->succeeded('c'));
        $this->assertTrue($backoff->ready('c'));

        // The poller logs one warning per failed attempt and one info on recovery.
        $channel = Channel::create(['kind' => 'notification', 'driver' => 'telegram', 'name' => 'Group', 'enabled' => true,
            'config' => ['bot_token' => 'test-token', 'chat_id' => '-100123']]);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 401)->push(['ok' => true, 'result' => []])]);
        Log::spy();
        Artisan::call('portal:telegram-poll', ['--once' => true]);
        Log::shouldHaveReceived('warning')->with('Telegram polling failed', \Mockery::on(fn ($c) => $c['retry_in_s'] === 10))->once();
        Carbon::setTestNow(now()->addSeconds(10));
        Artisan::call('portal:telegram-poll', ['--once' => true]);
        Log::shouldHaveReceived('info')->with('Telegram polling recovered', ['channel' => $channel->id, 'failures' => 1])->once();
    }
}
