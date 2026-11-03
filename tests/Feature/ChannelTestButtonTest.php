<?php

namespace Tests\Feature;

use App\Approval\Channels\WebhookChannel;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test button for notification channels (Telegram, Gotify, webhook).
 */
class ChannelTestButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_channels_send_test_messages(): void
    {
        $admin = User::factory()->admin()->create();
        $telegram = $this->channel('telegram', ['bot_token' => '123:secret-bot-token', 'chat_id' => '-100123']);
        $gotify = $this->channel('gotify', ['url' => 'https://gotify.example.com', 'app_token' => 'app-token']);
        $webhook = $this->channel('webhook', ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']);
        Http::fake();

        $this->onZone('admin')->actingAs($admin)->get("/admin/channels/{$gotify->id}")->assertOk()->assertSee(__('portal.channels.test_notify'));
        foreach ([$telegram, $gotify, $webhook] as $channel) {
            $this->onZone('admin')->actingAs($admin)->post("/admin/channels/{$channel->id}/test")
                ->assertSessionHasNoErrors()->assertSessionHas('status', __('portal.channels.test_notify_sent'));
        }

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/bot123:secret-bot-token/sendMessage'
            && $r['chat_id'] === '-100123' && $r['text'] === __('portal.channels.test_message'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://gotify.example.com/message' && $r->hasHeader('X-Gotify-Key', 'app-token'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.example.com/in' && $r['event'] === 'test'
            && $r->header('X-Signature')[0] === WebhookChannel::signature('hook-secret', $r->header('X-Timestamp')[0], $r->body()));
        $this->assertSame(3, AuditLog::where('action', 'channel.tested')->count());
    }

    public function test_failures_show_status_code_only(): void
    {
        $admin = User::factory()->admin()->create();
        $telegram = $this->channel('telegram', ['bot_token' => '123:secret-bot-token', 'chat_id' => '-100123']);
        Http::fake(['*' => Http::response(['description' => 'Unauthorized 123:secret-bot-token'], 401)]);

        $response = $this->onZone('admin')->actingAs($admin)->post("/admin/channels/{$telegram->id}/test");
        $response->assertSessionHasErrors(['test' => __('portal.channels.test_notify_failed', ['error' => 'HTTP 401'])]);
        $this->assertSame(['result' => 'failed'], AuditLog::where('action', 'channel.tested')->sole()->data);
        $this->onZone('admin')->actingAs(User::factory()->create())->post("/admin/channels/{$telegram->id}/test")->assertForbidden();
    }

    public function test_redirects_are_not_followed(): void
    {
        $admin = User::factory()->admin()->create();
        $webhook = $this->channel('webhook', ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']);
        $gotify = $this->channel('gotify', ['url' => 'https://gotify.example.com', 'app_token' => 'app-token']);
        Http::fake([
            'hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']),
            'gotify.example.com/*' => Http::response('', 307, ['Location' => 'https://elsewhere.example.net/message']),
            '*' => Http::response('ok'),
        ]);

        foreach ([$webhook, $gotify] as $channel) {
            $this->onZone('admin')->actingAs($admin)->post("/admin/channels/{$channel->id}/test")
                ->assertSessionHasErrors(['test' => __('portal.channels.test_notify_failed', ['error' => 'HTTP '.($channel->is($webhook) ? 302 : 307)])]);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254') || str_contains($r->url(), 'elsewhere.example.net'));
    }

    private function channel(string $driver, array $config): Channel
    {
        return Channel::create(['kind' => 'notification', 'driver' => $driver, 'name' => ucfirst($driver), 'enabled' => true, 'config' => $config]);
    }
}
