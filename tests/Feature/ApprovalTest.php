<?php

namespace Tests\Feature;

use App\Approval\ApprovalSettings;
use App\Approval\Channels\TelegramChannel;
use App\Approval\Channels\WebhookChannel;
use App\Approval\TelegramPoller;
use App\Enums\ApprovalStatus;
use App\Enums\GrantType;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\LocalSetting;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Redact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M4 core paths: push approval via Telegram, signed links (Gotify/webhook) and admin UI.
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
        Setting::put('admin_url', 'https://admin.example.com:8443');
    }

    public function test_telegram_approval_checks_group_and_allowlist_and_logs_guest_in(): void
    {
        $channel = Channel::create(['kind' => 'notification', 'driver' => 'telegram', 'name' => 'Group', 'enabled' => true,
            'config' => ['bot_token' => 'test-token', 'chat_id' => '-100123', 'allowed_user_ids' => '42, 43']]);
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]]),
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 5]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        // Code and approval available: the chooser links to the approval page.
        $this->enterPortal()->assertOk()->assertSee(__('portal.guest.choose.approval'))->assertSee('/portal?method=approval', false)
            ->assertDontSee(__('portal.guest.approval_request'));
        $this->onZone('guest')->get('/portal?method=approval')->assertOk()->assertSee(__('portal.guest.approval_request'))
            ->assertSee('action="'.route('guest.approval.request').'"', false);
        $this->requestApproval()->assertRedirect('/portal/approval');
        $request = ApprovalRequest::sole();
        $this->assertSame(7, $request->meta[$channel->id]['message_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '-100123'
            && str_contains($r['text'], 'Test Guest') && count($r['reply_markup']['inline_keyboard'][0]) === 3);
        $this->onZone('guest')->get('/portal/approval')->assertOk()->assertSee(__('portal.guest.approval_waiting_title'));

        $data = TelegramChannel::callbackData($request, '1d');
        $poller = app(TelegramPoller::class);
        // Wrong group and user outside the allowlist are rejected.
        $poller->handleCallback($channel, ['id' => 'c1', 'data' => $data, 'from' => ['id' => 42], 'message' => ['chat' => ['id' => -999]]]);
        $poller->handleCallback($channel, ['id' => 'c2', 'data' => $data, 'from' => ['id' => 99], 'message' => ['chat' => ['id' => -100123]]]);
        $this->assertSame(ApprovalStatus::Pending, $request->fresh()->status);

        $poller->handleCallback($channel, ['id' => 'c3', 'data' => $data, 'from' => ['id' => 42, 'first_name' => 'Approver'],
            'message' => ['chat' => ['id' => -100123]]]);
        $request->refresh();
        $this->assertSame(ApprovalStatus::Approved, $request->status);
        $this->assertSame('telegram', $request->decided_via);
        $grant = $request->grant;
        $this->assertSame(GrantType::Approval, $grant->type);
        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), $grant->expires_at->getTimestamp(), 5);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 7
            && str_contains($r['text'], 'Approver'));

        $this->onZone('guest')->get('/portal/approval')->assertOk()->assertViewHas('fields');

        $this->assertSame('cURL error for https://api.telegram.org/bot***/getUpdates',
            Redact::secrets('cURL error for https://api.telegram.org/bot123:ABC-def/getUpdates'));

        // The long-polling command processes updates (single pass).
        Artisan::call('portal:telegram-poll', ['--once' => true]);
        $this->assertSame(6, LocalSetting::get('telegram_offset.'.$channel->id));
    }

    public function test_gotify_and_webhook_send_signed_links_which_decide_only_on_post(): void
    {
        Channel::create(['kind' => 'notification', 'driver' => 'gotify', 'name' => 'Gotify', 'enabled' => true,
            'config' => ['url' => 'https://gotify.example.com', 'app_token' => 'app-token']]);
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);
        Http::fake();

        $this->enterPortal();
        $this->requestApproval();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://gotify.example.com/message' && $r->hasHeader('X-Gotify-Key', 'app-token')
            && str_starts_with($r['extras']['client::notification']['click']['url'], 'https://admin.example.com:8443/admin/approval-link/'));
        $webhook = Http::recorded()->map(fn (array $pair) => $pair[0])->first(fn (Request $r) => $r->url() === 'https://hooks.example.com/in');
        $this->assertSame(WebhookChannel::signature('hook-secret', $webhook->header('X-Timestamp')[0], $webhook->body()),
            $webhook->header('X-Signature')[0]);
        $link = parse_url($webhook['links']['deny']);
        $path = $link['path'].'?'.$link['query'];

        // GET only shows the page (link previews must not decide); tampered links are rejected.
        $this->onZone('admin')->get($path)->assertOk()->assertSee('Test Guest');
        $this->onZone('admin')->get($path.'x')->assertForbidden();
        $this->assertSame(ApprovalStatus::Pending, ApprovalRequest::sole()->status);

        // The deny link can only deny.
        $this->onZone('admin')->post($path, ['decision' => '1w'])->assertStatus(302);
        $this->assertSame(ApprovalStatus::Pending, ApprovalRequest::sole()->status);
        $this->onZone('admin')->post($path, ['decision' => 'deny'])->assertOk()->assertSee(__('portal.approvals.notify.denied', ['by' => 'Link']));
        $this->assertSame(ApprovalStatus::Denied, ApprovalRequest::sole()->status);
        $this->onZone('admin')->post($path, ['decision' => 'deny'])->assertOk()->assertSee(__('portal.approvals.closed'));
        $this->assertSame(ApprovalStatus::Denied, ApprovalRequest::sole()->status);

        $this->onZone('guest')->get('/portal/approval')->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.approval_denied.title'));
    }

    public function test_manager_approves_in_admin_ui_and_requests_expire(): void
    {
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);
        Http::fake();
        $this->enterPortal();
        $this->requestApproval();
        $request = ApprovalRequest::sole();

        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get('/admin/approvals')->assertOk()->assertSee('Test Guest');
        $this->onZone('admin')->actingAs($manager)->post("/admin/approvals/{$request->id}", ['decision' => 'custom', 'custom_hours' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame(7200, $request->fresh()->grant->duration_s);
        $this->assertSame($manager->id, $request->fresh()->grant->created_by);

        // Global cap of open requests per site.
        config(['portal.approval.max_pending_per_site' => 0]);
        $this->requestApproval()->assertStatus(429)->assertSee(__('portal.guest.error_pages.approval_busy.title'));
        config(['portal.approval.max_pending_per_site' => 10]);

        // A new request of the same device after the decision expires after 15 minutes.
        $this->requestApproval();
        $this->travel(16)->minutes();
        $this->onZone('guest')->get('/portal/approval')->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.approval_expired.title'));
    }

    public function test_admin_configures_channels_with_encrypted_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk();
        $this->onZone('admin')->actingAs($admin)->get('/admin/channels/create?driver=telegram')->assertOk();
        $this->onZone('admin')->actingAs($admin)->post('/admin/channels', ['driver' => 'telegram', 'name' => 'Group', 'enabled' => '1',
            'config' => ['bot_token' => 'test-token', 'chat_id' => '-100123']])->assertSessionHasNoErrors();
        $channel = Channel::sole();
        $this->assertStringNotContainsString('test-token', (string) $channel->getRawOriginal('config'));

        // Empty secret keeps the stored one.
        $this->onZone('admin')->actingAs($admin)->get("/admin/channels/{$channel->id}")->assertOk()->assertDontSee('test-token');
        $this->onZone('admin')->actingAs($admin)->put("/admin/channels/{$channel->id}", ['name' => 'Group', 'enabled' => '1',
            'config' => ['bot_token' => '', 'chat_id' => '-100456']])->assertSessionHasNoErrors();
        $this->assertSame(['test-token', '-100456'], [$channel->fresh()->conf('bot_token'), $channel->fresh()->conf('chat_id')]);

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings', ['notification_locale' => 'de', 'telegram_owner' => 'none'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(ApprovalSettings::isTelegramOwner());
        $this->onZone('admin')->actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();
    }

    private function enterPortal()
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => 'AA:BB:CC:00:00:01', 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }

    private function requestApproval()
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->post('/portal/approval', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1',
        ]);
    }
}
