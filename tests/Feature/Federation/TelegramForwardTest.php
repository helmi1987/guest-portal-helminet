<?php

namespace Tests\Feature\Federation;

use App\Approval\ApprovalLinks;
use App\Approval\ApprovalService;
use App\Approval\ApprovalSettings;
use App\Approval\Channels\TelegramChannel;
use App\Approval\TelegramPoller;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\Guest;
use App\Models\Instance;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Part H: Telegram owner selection, forwarding of button presses to the instance where the guest
 * waits (directly or through the hub), approval links with the origin's admin URL.
 */
class TelegramForwardTest extends TestCase
{
    use BuildsFederation, FederatedHttp, RefreshDatabase, SimulatesInstances;

    private string $channelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
        $this->fakedHosts = ['api.telegram.org'];
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $this->buildFederation();
        $this->channelId = $this->on('hub', fn () => Channel::create(['kind' => 'notification', 'driver' => 'telegram', 'name' => 'Group',
            'enabled' => true, 'config' => ['bot_token' => 'test-token', 'chat_id' => '-100123']])->id);
        $this->syncRound();
    }

    /** Approval request of a guest waiting at instance b. */
    private function requestOnB(): ApprovalRequest
    {
        return $this->on('b', function () {
            $this->on('b', fn () => Instance::find(app(LocalInstance::class)->id())->update(['admin_url' => 'https://b-admin.example.com:8443']));
            $site = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']);
            $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest', 'locale' => 'en']);

            return app(ApprovalService::class)->create($guest, $site, 'AA:BB:CC:00:00:01');
        });
    }

    private function press(string $name, ApprovalRequest $request, string $id): void
    {
        $this->on($name, fn () => app(TelegramPoller::class)->handleCallback(Channel::findOrFail($this->channelId), [
            'id' => $id, 'data' => TelegramChannel::callbackData($request, '1d'), 'from' => ['id' => 42, 'first_name' => 'Approver'],
            'message' => ['chat' => ['id' => -100123]]]));
    }

    private function assertAnswered(string $id, string $key): void
    {
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === $id
            && $r['text'] === __('portal.approvals.telegram.'.$key, [], 'en'));
    }

    public function test_owner_defaults_to_the_hub_and_is_selectable(): void
    {
        $this->assertTrue($this->on('hub', fn () => ApprovalSettings::isTelegramOwner()));
        $this->assertFalse($this->on('a', fn () => ApprovalSettings::isTelegramOwner()));
        $idA = $this->idOf('a');

        $this->on('hub', function () use ($idA) {
            $admin = User::factory()->admin()->create();
            $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('A')
                ->assertSee(__('portal.settings.telegram_owner_none'));
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings', ['notification_locale' => 'en', 'telegram_owner' => 'nope'])
                ->assertSessionHasErrors('telegram_owner');
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings', ['notification_locale' => 'en', 'telegram_owner' => $idA])
                ->assertSessionHasNoErrors();
        });
        $this->syncRound();
        $this->assertTrue($this->on('a', fn () => ApprovalSettings::isTelegramOwner()));
        $this->assertFalse($this->on('hub', fn () => ApprovalSettings::isTelegramOwner()));

        // "none" turns polling off everywhere; a removed owner falls back to the hub.
        $this->on('hub', fn () => Setting::put('telegram_owner', 'none'));
        $this->assertNull($this->on('hub', fn () => ApprovalSettings::telegramOwnerId()));
        $this->on('hub', fn () => Setting::put('telegram_owner', '01JUNKNOWN0000000000000000'));
        $this->assertSame($this->idOf('hub'), $this->on('hub', fn () => ApprovalSettings::telegramOwnerId()));
    }

    public function test_press_is_forwarded_to_the_origin_directly_and_through_the_hub(): void
    {
        $request = $this->requestOnB();
        // Links in Gotify/webhook messages use the admin URL of the instance where the guest waits.
        $this->assertStringStartsWith('https://b-admin.example.com:8443/admin/approval-link/',
            $this->on('b', fn () => ApprovalLinks::for($request)['1d']));
        // The instance's own admin URL wins over the shared setting (also for the OIDC redirect URI).
        $this->on('hub', fn () => Setting::put('admin_url', 'https://shared-admin.example.com:8443'));
        $this->syncRound();
        $this->assertSame('https://b-admin.example.com:8443', $this->on('b', fn () => ApprovalSettings::adminUrl()));
        $this->assertFalse($this->on('b', fn () => ApprovalSettings::adminUrlMissing()));
        $this->assertSame('https://shared-admin.example.com:8443', $this->on('a', fn () => ApprovalSettings::adminUrl()));

        // The hub (owner, direct peer of b) forwards; b decides and edits the message.
        $this->press('hub', $request, 'c1');
        $this->assertSame(ApprovalStatus::Approved, $this->on('b', fn () => $request->fresh()->status));
        $this->assertSame('telegram', $this->on('b', fn () => $request->fresh()->decided_via));
        $this->assertAnswered('c1', 'done');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 7);

        // Already decided: closed.
        $this->press('hub', $request, 'c2');
        $this->assertAnswered('c2', 'closed');

        // A peer that is not the Telegram owner cannot decide (the hub refuses to relay it).
        $second = $this->on('b', fn () => app(ApprovalService::class)->create(Guest::sole(), Site::sole(), 'AA:BB:CC:00:00:02'));
        $this->press('a', $second, 'c2a');
        $this->assertAnswered('c2a', 'unreachable');
        $this->assertSame(ApprovalStatus::Pending, $this->on('b', fn () => $second->fresh()->status));

        // Owner a reaches b only through the hub.
        $idA = $this->idOf('a');
        $this->on('hub', fn () => Setting::put('telegram_owner', $idA));
        $this->syncRound();
        $this->press('a', $second, 'c3');
        $this->assertSame(ApprovalStatus::Approved, $this->on('b', fn () => $second->fresh()->status));
        $this->assertAnswered('c3', 'done');

        // Origin down: the clicker is told.
        $third = $this->on('b', fn () => app(ApprovalService::class)->create(Guest::sole(), Site::sole(), 'AA:BB:CC:00:00:03'));
        $this->down = ['b'];
        $this->press('hub', $third, 'c4');
        $this->assertAnswered('c4', 'unreachable');
        $this->down = [];
        $this->assertSame(ApprovalStatus::Pending, $this->on('b', fn () => $third->fresh()->status));
    }
}
