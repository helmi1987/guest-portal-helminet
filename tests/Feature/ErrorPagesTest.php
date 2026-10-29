<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Custom HTML error pages (guest/admin look, translated, no details) and JSON for the APIs.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
        Setting::put('admin_url', 'https://admin.example.com:8443');
        Channel::create(['kind' => 'notification', 'driver' => 'telegram', 'name' => 'Group', 'enabled' => true,
            'config' => ['bot_token' => 'test-token', 'chat_id' => '-100123', 'allowed_user_ids' => '42']]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]])]);
    }

    public function test_approval_without_terms_then_with_terms_is_not_throttled_by_other_requests(): void
    {
        $this->enterPortal()->assertOk();
        // Page assets (branding files) must not use up the counter of the approval request.
        for ($i = 0; $i < 10; $i++) {
            $this->onZone('guest')->get('/branding/theme/global.css');
        }

        $this->requestApproval(['terms' => null])->assertRedirect()->assertSessionHasErrors('terms');
        $this->requestApproval()->assertRedirect('/portal/approval');
    }

    public function test_route_throttle_on_guest_zone_renders_portal_page_with_translated_text(): void
    {
        $this->enterPortal()->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->requestApproval(['terms' => null], 'de')->assertSessionHasErrors('terms');
        }

        $response = $this->requestApproval(['terms' => null], 'de')->assertStatus(429);
        $seconds = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $seconds);
        $response->assertSee('gp-error-page', false)->assertSee('gp-guest', false)
            ->assertSee('Zu viele Versuche. Bitte warte einen Moment und versuche es dann erneut.')
            ->assertSee("Bitte versuche es in $seconds Sekunde")
            ->assertSee('href="/portal"', false)
            ->assertDontSee('Too Many Requests');
    }

    public function test_token_mismatch_on_admin_zone_renders_admin_page(): void
    {
        Route::middleware('web')->post('/admin/error-test-419', fn () => throw new TokenMismatchException('secret detail'));

        $this->onZone('admin')->withHeader('Accept-Language', 'it')->post('/admin/error-test-419')
            ->assertStatus(419)
            ->assertSee('gp-error-page', false)->assertSee('gp-admin', false)
            ->assertSee('La tua sessione è scaduta. Ricarica la pagina.')
            ->assertSee('href="/admin"', false)
            ->assertDontSee('secret detail');
    }

    public function test_server_error_uses_built_in_look_without_queries_or_details(): void
    {
        Route::middleware('web')->get('/error-test-500', fn () => throw new RuntimeException('secret detail'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->onZone('guest')->withHeader('Accept-Language', 'fr')->get('/error-test-500');
        $queries = DB::getQueryLog();

        $response->assertStatus(500)->assertSee('gp-error-page', false)
            ->assertSee('Une erreur est survenue')
            ->assertDontSee('secret detail')->assertDontSee('RuntimeException');
        $this->assertSame([], $queries);
    }

    public function test_unknown_page_on_guest_zone_is_styled(): void
    {
        $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/does-not-exist')
            ->assertNotFound()->assertSee('gp-error-page', false)->assertSee('Page not found');
    }

    public function test_internal_and_federation_errors_stay_json(): void
    {
        $this->onZone('internal')->post('/internal/radius/authorize')
            ->assertForbidden()->assertHeader('Content-Type', 'application/json');
        $this->onZone('internal')->get('/does-not-exist')
            ->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->onZone('federation')->get('/does-not-exist')
            ->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    private function enterPortal()
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => 'AA:BB:CC:00:00:01', 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }

    private function requestApproval(array $override = [], string $locale = 'en')
    {
        $data = array_filter(array_replace(['first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1'], $override),
            fn ($v) => $v !== null);

        return $this->onZone('guest')->withHeader('Accept-Language', $locale)->post('/portal/approval', $data);
    }
}
