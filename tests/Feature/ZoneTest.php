<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_landing_is_served_on_guest_port_only(): void
    {
        $this->onZone('guest')->get('/')->assertOk()->assertSee('lang/de');
        $this->onZone('admin')->get('/')->assertNotFound();
    }

    public function test_admin_routes_are_not_exposed_on_guest_port(): void
    {
        $this->onZone('guest')->get('/admin/login')->assertNotFound();
        $this->onZone('guest')->get('/admin')->assertNotFound();
        $this->onZone('admin')->get('/admin/login')->assertOk();
    }

    public function test_requests_without_zone_are_rejected(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/admin/login')->assertNotFound();
    }

    public function test_guest_locale_is_detected_from_browser_and_switchable(): void
    {
        $this->onZone('guest')->withHeader('Accept-Language', 'it-CH,it;q=0.9')
            ->get('/')->assertSee('Benvenuti nel Wi-Fi ospiti');

        $this->onZone('guest')->get('/lang/fr')->assertRedirect()->assertSessionHas('locale', 'fr');
        $this->onZone('guest')->withSession(['locale' => 'fr'])->withHeader('Accept-Language', 'de')
            ->get('/')->assertSee('Bienvenue sur le Wi-Fi invités');
    }

    public function test_internal_radius_endpoint_requires_token_and_rejects(): void
    {
        $this->onZone('guest')->postJson('/internal/radius/authorize')->assertNotFound();
        $this->onZone('internal')->postJson('/internal/radius/authorize')->assertForbidden();
        $this->onZone('internal')
            ->withBasicAuth('radius', 'test-internal-token')
            ->postJson('/internal/radius/authorize', ['User-Name' => ['value' => ['x']]])
            ->assertUnauthorized();
    }

    public function test_default_zone_all_is_ignored_in_production(): void
    {
        config(['portal.default_zone' => 'all']);
        $this->get('/admin/login')->assertOk();

        $this->app['env'] = 'production';
        $this->get('/admin/login')->assertNotFound();
    }

    public function test_livewire_endpoints_exist_on_admin_port_only(): void
    {
        $this->onZone('guest')->get('/livewire/livewire.js')->assertNotFound();
        $this->onZone('guest')->postJson('/livewire/update')->assertNotFound();
        $this->onZone('admin')->get('/livewire/livewire.js')->assertOk();
    }

    public function test_admin_session_cookie_is_same_site_strict(): void
    {
        $this->onZone('admin')->get('/admin/login')->assertOk();
        $this->assertSame('strict', config('session.same_site'));
        $this->assertSame('portal_admin_session', config('session.cookie'));
    }
}
