<?php

namespace Tests\Feature;

use App\Guest\GuestPreview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Toolbox "Guest portal" (CONCEPT.md §9): frames the guest texts preview with language, site, screen and
 * device selectors; admin only.
 */
class ToolboxPortalPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com'];
        $this->siteB = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7', 'settings' => $settings]);
        $this->siteA = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7', 'settings' => $settings]);
    }

    private function admin(): static
    {
        return $this->onZone('admin')->withHeader('Accept-Language', 'de')->actingAs(User::factory()->admin()->create());
    }

    public function test_page_renders_selectors_and_the_preview_of_the_first_site(): void
    {
        $response = $this->admin()->get('/admin/toolbox/portal')->assertOk()
            ->assertSee(__('portal.toolbox.portal.title'))
            ->assertSee('<select name="locale"', false)
            ->assertSee('<select name="site"', false)
            ->assertSee('<select name="screen"', false)
            ->assertSee('<optgroup label="'.__('portal.toolbox.portal.groups.errors').'">', false)
            ->assertSee('name="device" value="desktop"', false)
            // First site by name is the default branding, chooser the default screen.
            ->assertSee('/admin/texts/preview?locale=de&amp;screen=chooser&amp;site='.$this->siteA->id, false)
            ->assertSee('<option value="'.$this->siteA->id.'" selected', false);

        foreach (GuestPreview::screens() as $screen) {
            $response->assertSee('<option value="'.$screen.'"', false);
        }
    }

    public function test_chosen_locale_site_and_screen_go_into_the_iframe_url(): void
    {
        $this->admin()->get('/admin/toolbox/portal?'.http_build_query(['locale' => 'it', 'site' => $this->siteB->id, 'screen' => 'status']))
            ->assertOk()
            ->assertSee('/admin/texts/preview?locale=it&amp;screen=status&amp;site='.$this->siteB->id, false)
            ->assertSee('<option value="it" selected', false)
            ->assertSee('<option value="status" selected', false);

        // Unknown values fall back to the defaults.
        $this->admin()->get('/admin/toolbox/portal?locale=xx&screen=nope&site=999999')->assertOk()
            ->assertSee('/admin/texts/preview?locale=de&amp;screen=chooser&amp;site='.$this->siteA->id, false);
    }

    public function test_without_a_site_the_preview_shows_the_global_branding(): void
    {
        Site::query()->get()->each->delete();
        $this->admin()->get('/admin/toolbox/portal')->assertOk()
            ->assertDontSee('<select name="site"', false)
            ->assertSee(__('portal.toolbox.portal.no_site'))
            ->assertSee('/admin/texts/preview?locale=de&amp;screen=chooser"', false);
    }

    public function test_device_toggle_changes_the_width_class(): void
    {
        $this->admin()->get('/admin/toolbox/portal')->assertOk()
            ->assertSee('class="w-[390px] h-[48rem]', false)
            ->assertSee('name="device" value="phone" checked', false);
        $this->admin()->get('/admin/toolbox/portal?device=desktop')->assertOk()
            ->assertSee('class="w-full h-[48rem]', false)
            ->assertSee('name="device" value="desktop" checked', false);
    }

    public function test_screen_groups_cover_all_preview_screens_once(): void
    {
        $grouped = array_merge(...array_values(GuestPreview::screenGroups()));
        $this->assertSame(['signin', 'status', 'errors'], array_keys(GuestPreview::screenGroups()));
        $this->assertEqualsCanonicalizing(GuestPreview::screens(), $grouped);
        $this->assertCount(count(GuestPreview::screens()), $grouped);
    }

    public function test_toolbox_index_links_the_page(): void
    {
        $this->admin()->get('/admin/toolbox')->assertOk()
            ->assertSee(route('admin.toolbox.portal'), false)
            ->assertSee(__('portal.toolbox.portal.title'));
    }

    public function test_page_is_admin_only_and_admin_zone_only(): void
    {
        $this->onZone('admin')->get('/admin/toolbox/portal')->assertRedirect(route('login'));
        $this->onZone('admin')->actingAs(User::factory()->create())->get('/admin/toolbox/portal')->assertForbidden();
        $this->onZone('guest')->actingAs(User::factory()->admin()->create())->get('/admin/toolbox/portal')->assertNotFound();
    }

    public function test_nginx_still_allows_framing_for_the_preview_route_only(): void
    {
        $conf = file_get_contents(base_path('docker/nginx/portal.conf.template'));
        $this->assertSame(1, preg_match('/map "\$portal_zone:\$request_uri" \$portal_frame_self \{(.*?)\}/s', $conf, $m));
        $this->assertSame(1, preg_match_all('/^\s*"[^"]+"\s+1;$/m', $m[1]));
        $this->assertStringContainsString('"~^admin:/admin/texts/preview(\?|$)" 1;', $m[1]);
    }
}
