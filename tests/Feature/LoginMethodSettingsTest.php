<?php

namespace Tests\Feature;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\SocialSettings;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.22.0: switches for the guest login methods in the settings (CONCEPT.md §9, decision 2026-11-03).
 * v0.25.0: display order of the methods (CONCEPT.md §7, decision 2026-11-04).
 */
class LoginMethodSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_renders_for_admins_only_with_prerequisite_hints(): void
    {
        $admin = User::factory()->admin()->create();

        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.settings.login_methods.title'))
            ->assertSee(__('portal.settings.login_methods.methods.code'))
            ->assertSee(__('portal.settings.login_methods.methods.social'))
            // SMS and approval are on by default but have no provider / channel; social is off (no hint).
            ->assertSee(__('portal.settings.login_methods.hint_sms'))
            ->assertSee(__('portal.settings.login_methods.hint_approval'))
            ->assertDontSee(__('portal.settings.login_methods.hint_social'));

        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get('/admin/settings')->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->post('/admin/settings/login-methods', ['methods' => ['code' => '1']])->assertForbidden();
        $this->assertSame(LoginMethods::DEFAULTS, LoginMethods::all());
    }

    public function test_hints_follow_the_prerequisites(): void
    {
        $admin = User::factory()->admin()->create();
        Channel::create(['kind' => 'sms', 'driver' => 'clickatell', 'name' => 'SMS', 'enabled' => true, 'config' => ['api_key' => 'k1']]);
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);
        LoginMethods::save([LoginMethods::SOCIAL => true]);

        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertDontSee(__('portal.settings.login_methods.hint_sms'))
            ->assertDontSee(__('portal.settings.login_methods.hint_approval'))
            ->assertSee(__('portal.settings.login_methods.hint_social'));

        SocialSettings::save(['providers' => ['github' => ['enabled' => true, 'client_id' => 'gh-client']]], ['github' => 'gh-test-secret']);
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertDontSee(__('portal.settings.login_methods.hint_social'));
    }

    public function test_switches_are_saved_and_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/login-methods', ['methods' => [
            'code' => '1', 'sms' => '0', 'approval' => '0', 'social' => '1',
        ]])->assertRedirect('/admin/settings')->assertSessionHasNoErrors()->assertSessionHas('status', __('portal.saved'));

        $this->assertSame(['code' => true, 'account' => false, 'sms' => false, 'approval' => false, 'social' => true], LoginMethods::all());
        $this->assertSame(['enabled' => ['code', 'social'], 'disabled' => ['account', 'sms', 'approval'], 'account_duration_s' => 86400,
            'order' => LoginMethods::ALL],
            AuditLog::where('action', 'settings.login_methods')->sole()->data);

        // Missing fields (unchecked boxes without hidden input) count as off.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/login-methods', ['methods' => ['sms' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['code' => false, 'account' => false, 'sms' => true, 'approval' => false, 'social' => false], LoginMethods::all());
    }

    public function test_at_least_one_method_stays_on(): void
    {
        $admin = User::factory()->admin()->create();

        $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/login-methods', ['methods' => [
            'code' => '0', 'sms' => '0', 'approval' => '0', 'social' => '0',
        ]])->assertRedirect('/admin/settings')->assertSessionHasErrors(['methods' => __('portal.settings.login_methods.at_least_one')]);

        $this->assertSame(LoginMethods::DEFAULTS, LoginMethods::all());
        $this->assertSame(0, AuditLog::where('action', 'settings.login_methods')->count());
    }

    /** @return list<string> methods in the order of their rows on the settings page */
    private function settingsRows(User $admin): array
    {
        $html = $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        preg_match_all('/data-order-item="([a-z]+)"/', $html, $m);

        return array_values(array_intersect($m[1], LoginMethods::ALL));
    }

    public function test_default_order_is_the_previous_fixed_order(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(LoginMethods::ALL, LoginMethods::order());
        $this->assertSame(['code', 'account', 'sms', 'approval', 'social'], LoginMethods::order());
        $this->assertSame(LoginMethods::ALL, $this->settingsRows($admin));
        $this->assertSame(LoginMethods::ALL, array_keys(LoginMethods::available()));

        // A value stored before v0.25.0 (no order) keeps the default order; saving the switches keeps it.
        Setting::put(LoginMethods::KEY, ['code' => true, 'sms' => false, 'approval' => true, 'social' => false]);
        $this->assertSame(LoginMethods::ALL, LoginMethods::order());
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/login-methods', ['methods' => ['code' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertSame(LoginMethods::ALL, LoginMethods::order());
    }

    public function test_order_is_saved_audited_and_shown(): void
    {
        $admin = User::factory()->admin()->create();
        $order = ['social', 'approval', 'code', 'sms', 'account'];

        $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/login-methods', [
            'methods' => ['code' => '1', 'sms' => '1', 'approval' => '1'], 'order' => $order,
        ])->assertRedirect('/admin/settings')->assertSessionHasNoErrors();

        $this->assertSame($order, LoginMethods::order());
        $this->assertSame($order, array_keys(LoginMethods::available()));
        $this->assertSame($order, AuditLog::where('action', 'settings.login_methods')->sole()->data['order']);
        $this->assertSame($order, $this->settingsRows($admin));

        // Saving the switches without order fields keeps the order.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/login-methods', ['methods' => ['code' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertSame($order, LoginMethods::order());
        $this->assertSame(['code' => true, 'account' => false, 'sms' => false, 'approval' => false, 'social' => false], LoginMethods::all());
    }

    public function test_arrow_buttons_move_a_method_without_js(): void
    {
        $admin = User::factory()->admin()->create();
        $post = fn (array $data) => $this->onZone('admin')->actingAs($admin)->post('/admin/settings/login-methods',
            ['methods' => ['code' => '1']] + $data)->assertSessionHasNoErrors();

        $html = $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        // First row has no usable up, last row no usable down.
        $this->assertMatchesRegularExpression('/value="code:up"[^>]*\sdisabled(?!:)/', $html);
        $this->assertMatchesRegularExpression('/value="social:down"[^>]*\sdisabled(?!:)/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="code:down"[^>]*\sdisabled(?!:)/', $html);
        // One order field per method (5) and per social provider (4).
        $this->assertSame(9, substr_count($html, 'name="order[]"'));

        $post(['order' => LoginMethods::ALL, 'move' => 'sms:up']);
        $this->assertSame(['code', 'sms', 'account', 'approval', 'social'], LoginMethods::order());
        // Without order fields the move applies to the stored order.
        $post(['move' => 'code:down']);
        $this->assertSame(['sms', 'code', 'account', 'approval', 'social'], LoginMethods::order());
        // At the edge nothing changes.
        $post(['move' => 'sms:up']);
        $post(['move' => 'social:down']);
        $this->assertSame(['sms', 'code', 'account', 'approval', 'social'], LoginMethods::order());
        $this->assertSame(4, AuditLog::where('action', 'settings.login_methods')->count());
    }

    public function test_invalid_order_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $invalid = [
            ['code', 'sms', 'approval', 'social'],                     // missing
            ['code', 'code', 'sms', 'approval', 'social'],             // duplicate
            ['code', 'account', 'sms', 'approval', 'facebook'],        // unknown
            ['code', 'account', 'sms', 'approval', 'social', 'other'], // too many
            'code,account,sms,approval,social',                        // not a list
            ['a' => 'code', 'account', 'sms', 'approval', 'social'],   // not a list
        ];
        foreach ($invalid as $order) {
            $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/login-methods', [
                'methods' => ['sms' => '1'], 'order' => $order,
            ])->assertRedirect('/admin/settings')->assertSessionHasErrors(['order' => __('portal.settings.order.invalid')]);
        }
        foreach (['code:left', 'facebook:up', 'code'] as $move) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/login-methods', ['methods' => ['sms' => '1'], 'move' => $move])
                ->assertSessionHasErrors('move');
        }
        $this->assertSame(LoginMethods::DEFAULTS, LoginMethods::all());
        $this->assertSame(LoginMethods::ALL, LoginMethods::order());
        $this->assertSame(0, AuditLog::where('action', 'settings.login_methods')->count());
    }

    public function test_stored_order_is_read_leniently(): void
    {
        // Unknown and duplicate entries are dropped, missing methods appended in default order.
        Setting::put(LoginMethods::KEY, ['code' => true, 'order' => ['social', 'bogus', 'social', 'sms', 7]]);
        $this->assertSame(['social', 'sms', 'code', 'account', 'approval'], LoginMethods::order());

        foreach (['social', ['a' => 'social'], null, 5] as $broken) {
            Setting::put(LoginMethods::KEY, ['code' => true, 'order' => $broken]);
            $this->assertSame(LoginMethods::ALL, LoginMethods::order());
        }
        Setting::put(LoginMethods::KEY, 'garbage');
        $this->assertSame(LoginMethods::ALL, LoginMethods::order());
        $this->assertSame(LoginMethods::DEFAULTS, LoginMethods::all());

        // save() normalizes too.
        LoginMethods::save(LoginMethods::DEFAULTS, ['approval', 'approval', 'nope']);
        $this->assertSame(['approval', 'code', 'account', 'sms', 'social'], Setting::get(LoginMethods::KEY)['order']);
    }
}
