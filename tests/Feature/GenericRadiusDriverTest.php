<?php

namespace Tests\Feature;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\SocialSettings;
use App\Enums\ExpiryMode;
use App\Federation\FederatedSecrets;
use App\Federation\Federation;
use App\Jobs\DisconnectSession;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsVersion;
use App\Models\User;
use App\Nas\Generic\GenericSettings;
use App\Nas\Generic\Presets;
use App\Nas\GenericRadiusDriver;
use App\Nas\HotspotContext;
use App\Nas\MikrotikRos7Driver;
use App\Nas\NasDrivers;
use App\Nas\RedirectSignals;
use App\Nas\SiteBoundDriver;
use App\Nas\UnifiDriver;
use App\Radius\Disconnector;
use App\Services\GrantManager;
use App\Support\Mac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Experimental generic RADIUS captive-portal driver (decision 2026-11-04, v0.28.0): redirect mapping of every
 * preset, handoff (POST, GET, CoovaChilli encoding), RADIUS reply and disconnect attributes, login page,
 * checklist, admin settings with presets and simulation, and the redirect signals of all three drivers.
 */
class GenericRadiusDriverTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private const CHALLENGE = '0123456789abcdef0123456789abcdef';

    private const SECRET = 'uam-secret-1';

    private Site $site;

    private NasClient $nas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-g', 'name' => 'Site G', 'driver' => GenericRadiusDriver::KEY,
            'settings' => ['hotspot_hosts' => ['hotspot.example.com', '10.1.0.1', 'securelogin.arubanetworks.com'],
                'portal_url' => 'https://portal.example.com', 'portal_address' => '10.0.0.10', 'radius_address' => '10.0.0.10']]);
        $this->nas = $this->site->nasClients()->create(['name' => 'Router G', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);
        TermsVersion::publish('en', 'Be nice.');
    }

    // --- Redirect ------------------------------------------------------------------------------------

    /** @return array<string, array{string, array<string, string>, array{0: ?string, 1: ?string, 2: ?string, 3: ?string}}> */
    public static function presetRedirects(): array
    {
        return [
            'cisco_wlc' => [Presets::CISCO_WLC, ['client_mac' => 'aa:bb:cc:00:00:01', 'ap_mac' => 'aa:bb:cc:00:00:99', 'wlan' => 'Guest',
                'switch_url' => 'https://hotspot.example.com/login.html', 'redirect' => 'http://example.com/'],
                [self::MAC, null, 'https://hotspot.example.com/login.html', 'http://example.com/']],
            'aruba' => [Presets::ARUBA, ['cmd' => 'login', 'mac' => 'aa:bb:cc:00:00:01', 'essid' => 'Guest', 'ip' => '10.0.0.20',
                'url' => 'http://example.com/'],
                [self::MAC, '10.0.0.20', 'https://securelogin.arubanetworks.com/cgi-bin/login', 'http://example.com/']],
            'pfsense' => [Presets::PFSENSE, ['login_url' => 'https://hotspot.example.com:8003/index.php?zone=guest', 'zone' => 'guest',
                'redirurl' => 'http://example.com/', 'mac' => 'aa-bb-cc-00-00-01', 'ip' => '10.0.0.20'],
                [self::MAC, '10.0.0.20', 'https://hotspot.example.com:8003/index.php?zone=guest', 'http://example.com/']],
            'opnsense' => [Presets::OPNSENSE, ['nas_host' => '10.1.0.1:8000', 'zone' => '0', 'mac' => 'aa:bb:cc:00:00:01',
                'ip' => '10.0.0.20', 'redirurl' => 'http://example.com/'],
                [self::MAC, '10.0.0.20', 'http://10.1.0.1:8000/api/captiveportal/access/logon/0/', 'http://example.com/']],
            'coovachilli' => [Presets::COOVACHILLI, ['res' => 'notyet', 'uamip' => '10.1.0.1', 'uamport' => '3990', 'challenge' => self::CHALLENGE,
                'mac' => 'AA-BB-CC-00-00-01', 'ip' => '10.0.0.20', 'userurl' => 'http://example.com/'],
                [self::MAC, '10.0.0.20', 'http://10.1.0.1:3990/logon', 'http://example.com/']],
            'ruckus' => [Presets::RUCKUS, ['client_mac' => 'aabb.cc00.0001', 'uip' => '10.0.0.20', 'sip' => 'hotspot.example.com',
                'url' => 'http://example.com/'],
                [self::MAC, '10.0.0.20', 'https://hotspot.example.com:9998/login', 'http://example.com/']],
            'custom' => [Presets::CUSTOM, ['mac' => 'aabbcc000001', 'ip' => '10.0.0.20', 'login' => 'https://hotspot.example.com/login'],
                [self::MAC, '10.0.0.20', 'https://hotspot.example.com/login', null]],
        ];
    }

    #[DataProvider('presetRedirects')]
    public function test_presets_read_their_vendor_redirects(string $preset, array $query, array $expected): void
    {
        $this->mapping($preset, $preset === Presets::CUSTOM ? ['param_login_url' => 'login'] : []);

        $context = $this->parse($query);

        $this->assertSame($expected, [$context->mac, $context->ip, $context->loginUrl, $context->origUrl]);
        $this->assertTrue($context->isComplete());
        $this->assertTrue(RedirectSignals::of($this->driver(), $this->request($query), $this->site)['has_login_url']);
    }

    public function test_mac_notations_and_hostile_input_are_rejected(): void
    {
        foreach (['aa:bb:cc:00:00:01', 'AA-BB-CC-00-00-01', 'aabb.cc00.0001', 'AABBCC000001'] as $mac) {
            $this->assertSame(self::MAC, Mac::fromRedirect($mac), $mac);
        }
        foreach (['aa:bb:cc:00:00', 'aa:bb-cc:00:00:01', 'aabb.cc00.00011', '<script>', 'aa:bb:cc:00:00:01x', null, ['x']] as $bad) {
            $this->assertNull(Mac::fromRedirect($bad));
        }

        $this->mapping(Presets::CISCO_WLC);
        $base = ['client_mac' => 'aa:bb:cc:00:00:01', 'redirect' => 'http://example.com/'];
        foreach (['http://hotspot.example.com/login.html', 'https://evil.example.net/login.html',
            'https://user:pw@hotspot.example.com/login.html', 'javascript:alert(1)', 'ftp://hotspot.example.com/'] as $url) {
            $this->assertNull($this->parse($base + ['switch_url' => $url])->loginUrl, $url);
        }
        $context = $this->parse(['client_mac' => '<b>x</b>', 'redirect' => 'javascript:alert(1)', 'switch_url' => 'https://hotspot.example.com/l']);
        $this->assertSame([null, null], [$context->mac, $context->origUrl]);
        $this->assertFalse($context->isComplete());

        // Template placeholders only take host/port-safe values; the host must still be a hotspot host.
        $this->mapping(Presets::COOVACHILLI);
        $chilli = ['mac' => 'AA-BB-CC-00-00-01', 'uamport' => '3990', 'challenge' => self::CHALLENGE];
        $this->assertNull($this->parse($chilli + ['uamip' => 'evil.example.net/x?'])->loginUrl);
        $this->assertNull($this->parse($chilli + ['uamip' => 'evil.example.net'])->loginUrl);
        $this->assertNull($this->parse($chilli)->loginUrl);
        $this->assertSame(['uamip' => '10.1.0.1', 'uamport' => '3990', 'challenge' => self::CHALLENGE],
            $this->parse($chilli + ['uamip' => '10.1.0.1', 'ignored' => 'x'])->params);

        // HTTP only with the switch on (any method).
        $this->mapping(Presets::COOVACHILLI, ['allow_http' => false]);
        $this->assertNull($this->parse($chilli + ['uamip' => '10.1.0.1'])->loginUrl);
        $this->mapping(Presets::COOVACHILLI, ['method' => 'POST']);
        $this->assertSame('http://10.1.0.1:3990/logon', $this->parse($chilli + ['uamip' => '10.1.0.1'])->loginUrl);
    }

    public function test_portal_entry_route_signals_and_error_pages(): void
    {
        $this->mapping(Presets::CISCO_WLC);

        // The NAS appends its query to /portal/g/<slug>; the portal continues at /portal with site=<slug>.
        $this->guest()->get('/portal/g/site-g?'.http_build_query(['client_mac' => 'aa:bb:cc:00:00:01', 'site' => 'other',
            'switch_url' => 'https://hotspot.example.com/login.html']))
            ->assertRedirect('/portal?'.http_build_query(['site' => 'site-g', 'client_mac' => 'aa:bb:cc:00:00:01',
                'switch_url' => 'https://hotspot.example.com/login.html']));
        $this->guest()->get('/portal/g/unknown?client_mac=aa:bb:cc:00:00:01')->assertSee(__('portal.guest.error_pages.no_hotspot.title'));
        Site::create(['slug' => 'site-m', 'name' => 'Site M', 'driver' => 'mikrotik_ros7', 'settings' => []]);
        $this->guest()->get('/portal/g/site-m?mac=aa:bb:cc:00:00:01')->assertSee(__('portal.guest.error_pages.no_hotspot.title'));

        // Configured NAS with a login URL outside the hotspot hosts: "misconfigured"; without MAC: "no hotspot".
        $this->enter(['switch_url' => 'https://evil.example.net/login.html'])->assertSee(__('portal.guest.error_pages.misconfigured.title'));
        $this->enter(['client_mac' => ''])->assertSee(__('portal.guest.error_pages.no_hotspot.title'));
        $this->enter()->assertOk()->assertDontSee(__('portal.guest.error_pages.no_hotspot.title'));

        // Code prefill from the original URL of the redirect.
        $this->enter(['redirect' => 'https://portal.example.com/portal?site=site-g&code=123%20456'])->assertOk();
        $this->assertSame('123456', session('portal_code'));
    }

    public function test_redirect_signals_of_all_three_drivers(): void
    {
        $code = 'https://portal.example.com/portal?site=x&code=654321';
        $mikrotik = Site::create(['slug' => 'site-m', 'name' => 'Site M', 'driver' => 'mikrotik_ros7', 'settings' => []]);
        $request = Request::create('/portal', 'GET', ['site' => 'site-m', 'link-login-only' => 'https://h.example.com/login', 'link-orig' => $code]);
        $this->assertSame(['has_login_url' => true, 'prefill_code' => '654321'], RedirectSignals::of($mikrotik->driver(), $request, $mikrotik));
        $this->assertSame(RedirectSignals::NONE, RedirectSignals::of($mikrotik->driver(), Request::create('/portal', 'GET', ['link-orig' => 'https://example.com/?code=654321']), $mikrotik));

        $unifi = Site::create(['slug' => 'site-u', 'name' => 'Site U', 'driver' => 'unifi', 'settings' => []]);
        $this->assertSame(['has_login_url' => false, 'prefill_code' => '654321'],
            RedirectSignals::of($unifi->driver(), Request::create('/portal', 'GET', ['url' => $code, 'link-login-only' => 'x']), $unifi));

        $this->mapping(Presets::RUCKUS);
        $this->assertSame(['has_login_url' => true, 'prefill_code' => '654321'],
            RedirectSignals::of($this->driver(), $this->request(['client_mac' => 'aa:bb:cc:00:00:01', 'url' => $code]), $this->site));
        $this->assertSame(RedirectSignals::NONE, RedirectSignals::of($this->driver(), $this->request(['url' => 'http://example.com/']), $this->site));

        $this->assertInstanceOf(MikrotikRos7Driver::class, $mikrotik->driver());
        $this->assertInstanceOf(UnifiDriver::class, $unifi->driver());
        $this->assertInstanceOf(SiteBoundDriver::class, $this->site->driver());
        $this->assertSame('Generic RADIUS captive portal (experimental)', NasDrivers::options()[GenericRadiusDriver::KEY]);
    }

    // --- Handoff and RADIUS ---------------------------------------------------------------------------

    public function test_cisco_post_handoff_maps_fields_and_radius_accepts(): void
    {
        $this->mapping(Presets::CISCO_WLC);
        $grant = $this->code();

        $response = $this->redeem($grant->code)->assertOk()->assertSee('action="https://hotspot.example.com/login.html"', false);
        $fields = $response->viewData('fields');
        $this->assertSame('POST', $response->viewData('method'));
        $this->assertSame(['buttonClicked', 'err_flag', 'username', 'password', 'redirect_url'], array_keys($fields));
        $this->assertSame(['4', '0'], [$fields['buttonClicked'], $fields['err_flag']]);
        $this->assertStringContainsString('/status/'.$grant->id.'/', $fields['redirect_url']);

        $reply = $this->authorize($fields['username'], $fields['password'], 'aa:bb:cc:00:00:01')->assertOk()->json();
        $this->assertArrayHasKey('reply:Session-Timeout', $reply);
        $this->assertArrayNotHasKey('reply:WISPr-Bandwidth-Max-Up', $reply);
        $this->assertSame(self::MAC, $grant->fresh()->activeDevice()->mac);
    }

    public function test_extra_fields_take_redirect_values_and_mapped_fields_win(): void
    {
        $this->mapping(Presets::RUCKUS, ['extra_fields' => "client_mac={client_mac}\nuip={uip}\nusername=evil\nmissing={nope}\nkey={handoff_secret}"],
            'shared-1');
        $handoff = $this->driver()->loginHandoff($this->parse(['client_mac' => 'aa:bb:cc:00:00:01', 'uip' => '10.0.0.20',
            'sip' => 'hotspot.example.com']), 'guser', 'gpass', 'https://portal.example.com/status');

        $this->assertSame('https://hotspot.example.com:9998/login', $handoff['url']);
        $this->assertSame(['client_mac' => 'aa:bb:cc:00:00:01', 'uip' => '10.0.0.20', 'username' => 'guser', 'key' => 'shared-1',
            'password' => 'gpass', 'url' => 'https://portal.example.com/status'], $handoff['fields']);
        $this->assertSame([], GenericSettings::extraFields("a b=1\nnovalue\n=x"));
        $this->assertCount(GenericSettings::MAX_EXTRA_FIELDS, GenericSettings::extraFields(implode("\n", array_map(fn ($i) => "f$i=$i", range(1, 15)))));
    }

    public function test_coovachilli_get_handoff_encodes_the_password_with_challenge_and_uamsecret(): void
    {
        foreach ([self::SECRET, null] as $secret) {
            $this->mapping(Presets::COOVACHILLI, [], $secret ?? '');
            $grant = $this->code();
            $this->guest()->get('/portal?'.http_build_query(['site' => 'site-g', 'uamip' => '10.1.0.1', 'uamport' => '3990',
                'challenge' => self::CHALLENGE, 'mac' => 'AA-BB-CC-00-00-01', 'userurl' => 'http://example.com/']));
            $response = $this->post('/portal/code', $this->codeForm($grant->code))->assertOk()->assertSee('data-handoff-get', false);
            $this->assertSame('GET', $response->viewData('method'));

            preg_match('/id="handoff" href="([^"]+)"/', $response->getContent(), $m);
            $href = html_entity_decode($m[1]);
            $this->assertStringStartsWith('http://10.1.0.1:3990/logon?', $href);
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $this->assertSame(['username', 'password', 'redirurl'], array_keys($query));

            // Decode as CoovaChilli does (redir.c): hex, XOR per 16-byte block with MD5(challenge + uamsecret) or the
            // raw challenge; the password is zero-padded to 16-byte blocks (24 characters: 32 bytes).
            $this->assertSame(64, strlen($query['password']));
            $key = $secret !== null ? md5(hex2bin(self::CHALLENGE).$secret, true) : hex2bin(self::CHALLENGE);
            $plain = '';
            foreach (str_split(hex2bin($query['password'])) as $i => $c) {
                $plain .= chr(ord($c) ^ ord($key[$i % 16]));
            }
            $this->assertSame(str_repeat("\0", 8), substr($plain, 24));
            $plain = rtrim($plain, "\0");
            $this->authorize($query['username'], $plain, 'AA-BB-CC-00-00-01')->assertOk();
            $this->assertNotSame($plain, $query['password']);
        }
    }

    public function test_reply_attributes_with_and_without_wispr(): void
    {
        $grant = Grant::query()->make(['rate_limit' => '2M/10M']);
        $this->mapping(Presets::CUSTOM);
        $this->assertSame(['reply:WISPr-Bandwidth-Max-Up' => 2_000_000, 'reply:WISPr-Bandwidth-Max-Down' => 10_000_000],
            $this->driver()->replyAttributes($grant));
        $this->assertSame([], $this->driver()->replyAttributes(Grant::query()->make(['rate_limit' => null])));
        $this->mapping(Presets::CUSTOM, ['wispr' => false]);
        $this->assertSame([], $this->driver()->replyAttributes($grant));

        $this->assertSame([512_000, 1_500_000], GenericRadiusDriver::rates('512k/1.5M 2M/4M 1M/2M 8'));
        $this->assertSame([1000, 2000], GenericRadiusDriver::rates('1000/2000'));
        $this->assertNull(GenericRadiusDriver::rates('fast'));
        $this->assertNull(GenericRadiusDriver::rates('0/1M'));
        $this->assertNull(GenericRadiusDriver::rates('5G/10G'));

        // Through the RADIUS API: WISPr in the Access-Accept (the dictionary is part of FreeRADIUS).
        $this->mapping(Presets::CISCO_WLC);
        $grant = $this->code();
        $grant->update(['rate_limit' => '1M/4M']);
        $fields = $this->redeem($grant->code)->viewData('fields');
        $this->assertSame(4_000_000, $this->authorize($fields['username'], $fields['password'], self::MAC)->json('reply:WISPr-Bandwidth-Max-Down'));
    }

    public function test_disconnect_attributes_follow_the_selection_and_the_ip_safety_rule(): void
    {
        $this->mapping(Presets::CISCO_WLC);
        $session = $this->openSession('sess-1', '10.0.0.20');
        $acct = $session->accounting()->sole();
        $user = $acct->username;

        $this->assertSame(['User-Name' => $user, 'Acct-Session-Id' => 'sess-1', 'Framed-IP-Address' => '10.0.0.20'], $this->disconnect($acct));
        $this->mapping(Presets::CISCO_WLC, ['disconnect_attrs' => ['Calling-Station-Id', 'Framed-IP-Address']]);
        $this->assertSame(['Framed-IP-Address' => '10.0.0.20', 'Calling-Station-Id' => self::MAC], $this->disconnect($acct));

        (new DisconnectSession($session->id))->handle(app(Disconnector::class));
        Process::assertRanTimes(fn (PendingProcess $p) => in_array('10.0.0.1:3799', $p->command, true)
            && $p->input === "Framed-IP-Address = \"10.0.0.20\"\nCalling-Station-Id = \"".self::MAC."\"\n", 1);

        // Stale session: the IP may be reused, it is left out; with nothing else left nothing is sent.
        $this->travel((int) config('portal.radius.stale_after') + 60)->seconds();
        $this->assertSame(['Calling-Station-Id' => self::MAC], $this->disconnect($acct));
        $this->mapping(Presets::CISCO_WLC, ['disconnect_attrs' => ['Framed-IP-Address']]);
        $this->assertNull($this->disconnect($acct));
        $this->mapping(Presets::CISCO_WLC, ['disconnect_attrs' => []]);
        $this->assertNull($this->disconnect($acct));

        // Stopped session: nothing to disconnect.
        $this->mapping(Presets::CISCO_WLC);
        $this->accounting('Stop', 'sess-1', $user);
        $this->assertNull($this->disconnect($acct->fresh()));
    }

    // --- Login page, checklist, admin -----------------------------------------------------------------

    public function test_login_page_copies_the_query_or_uses_the_template(): void
    {
        $this->mapping(Presets::CISCO_WLC);
        $page = $this->driver()->loginPage($this->site);
        $this->assertStringContainsString('location.replace("https://portal.example.com/portal/g/site-g" + location.search)', $page);
        $this->assertStringContainsString('href="https://portal.example.com/portal/g/site-g"', $page);

        $this->mapping(Presets::PFSENSE);
        $page = $this->driver()->loginPage($this->site);
        $this->assertStringContainsString('content="0; url=https://portal.example.com/portal?site=site-g&amp;login_url=$PORTAL_ACTION$&amp;zone=$PORTAL_ZONE$', $page);
        $this->assertStringNotContainsString('<script', $page);

        // Replicated hostile template (no form validation): dropped.
        $settings = $this->site->fresh()->settings;
        $settings['generic']['login_page_template'] = '"><script>alert(1)</script>';
        $this->site->update(['settings' => $settings]);
        $this->assertStringNotContainsString('alert(1)', $this->driver()->loginPage($this->site->fresh()));
        $this->assertStringContainsString('upload the downloaded login page', $this->driver()->loginPageFetch($this->site, $this->nas));
    }

    public function test_checklist_lists_radius_redirect_and_walled_garden_with_social_hosts(): void
    {
        $this->mapping(Presets::ARUBA);
        LoginMethods::save([LoginMethods::SOCIAL => true]);
        SocialSettings::save(['providers' => ['github' => ['enabled' => true, 'client_id' => 'github-client']]], ['github' => 'github-secret']);

        $text = $this->driver()->checklist($this->site->fresh());
        foreach (['Server: 10.0.0.10', '<RADIUS_SECRET>', 'https://portal.example.com/portal/g/site-g', 'portal.example.com  TCP 443',
            '10.0.0.10  TCP 443', 'github.com  TCP 443', '*.githubassets.com', 'wildcard', 'POST to https://securelogin.arubanetworks.com/cgi-bin/login',
            'Fields: user, password, url (success URL), cmd', 'Aruba: walled garden', 'MAC authentication'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }

        // Router configuration page: the checklist with the router's secret and CoA port.
        $config = $this->driver()->configScript($this->site->fresh(), $this->nas);
        $this->assertStringContainsString('Shared secret: '.$this->nas->radius_secret, $config);
        $this->assertStringContainsString('UDP port 3799', $config);
        $commands = $this->driver()->setupCommands($this->site, []);
        $this->assertArrayHasKey('uninstall_network', $commands['uninstall']);
        $this->assertSame(['network' => [], 'container' => []], $this->driver()->setupFields());

        // Hostile replicated values never reach the checklist.
        $settings = $this->site->fresh()->settings;
        $settings['generic']['field_username'] = 'user"; rm -rf /';
        $settings['hotspot_hosts'] = ['bad host"<x>'];
        $this->site->update(['settings' => $settings]);
        $text = $this->driver()->checklist($this->site->fresh());
        $this->assertStringNotContainsString('rm -rf', $text);
        $this->assertStringNotContainsString('<x>', $text);
    }

    public function test_site_page_setup_page_and_simulation(): void
    {
        $this->mapping(Presets::CISCO_WLC);
        $admin = User::factory()->admin()->create();

        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()
            ->assertSee(__('portal.generic.title'))->assertSee(__('portal.generic.experimental'))
            ->assertSee(__('portal.generic.mac_auth_note'))->assertSee(__('portal.generic.presets.cisco_wlc.note'))
            ->assertSee(__('portal.sites.routers'))->assertDontSee(__('portal.router_api.title'))
            ->assertDontSee(__('portal.setup.checklist.title'))->assertDontSee(__('portal.wizard.open'))
            ->assertSee('value="client_mac"', false);

        $this->admin($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.generic.checklist_title'))->assertSee('/portal/g/site-g')->assertSee(__('portal.generic.mapping_title'))
            ->assertSee('buttonClicked=4')->assertDontSee($this->nas->radius_secret);
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/wizard")->assertRedirect(route('admin.sites.setup', $this->site));
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/config")->assertOk()
            ->assertSee('Shared secret: '.$this->nas->radius_secret);

        $page = $this->admin($admin)->get("/admin/sites/{$this->site->id}/generic/simulate")->assertOk()
            ->assertSee('data-generic-sim-status="complete"', false)->assertSee('https://portal.example.com/portal/g/site-g?client_mac=aa%3Abb%3Acc%3A00%3A00%3A01', false)
            ->assertSee('https://hotspot.example.com/login.html')->assertSee('buttonClicked')->assertSee(self::MAC);
        $this->assertSame(0, SessionSummary::count());

        // A fixed login URL outside the hotspot hosts: simulation shows "misconfigured".
        $this->mapping(Presets::ARUBA);
        $settings = $this->site->fresh()->settings;
        $settings['hotspot_hosts'] = ['other.example.com'];
        $this->site->update(['settings' => $settings]);
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/generic/simulate")->assertOk()
            ->assertSee('data-generic-sim-status="misconfigured"', false);

        // Every preset simulates as complete with its own sample values.
        $this->site->update(['settings' => array_merge($this->site->fresh()->settings, ['hotspot_hosts' => ['hotspot.example.com']])]);
        foreach (Presets::keys() as $preset) {
            $this->mapping($preset, $preset === Presets::ARUBA ? ['login_url' => 'https://hotspot.example.com/cgi-bin/login'] : []);
            $this->admin($admin)->get("/admin/sites/{$this->site->id}/generic/simulate")->assertOk()
                ->assertSee('data-generic-sim-status="'.($preset === Presets::CUSTOM ? 'incomplete' : 'complete').'"', false);
        }
    }

    public function test_admin_saves_the_mapping_with_validation_and_an_encrypted_secret(): void
    {
        $admin = User::factory()->admin()->create();
        $this->save($admin, ['handoff_secret' => self::SECRET])->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sites.edit', $this->site).'#generic');
        $site = $this->site->fresh();
        $this->assertSame('client_mac', GenericSettings::forSite($site)['param_mac']);
        $this->assertSame("buttonClicked=4\nerr_flag=0", GenericSettings::forSite($site)['extra_fields']);
        $this->assertSame(self::SECRET, GenericSettings::secret($site));
        $this->assertStringNotContainsString(self::SECRET, DB::table('sites')->where('id', $site->id)->value('settings'));
        $audit = AuditLog::query()->where('action', 'site.generic_updated')->sole();
        $this->assertTrue($audit->data['credential_changed']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($audit->data));
        $page = $this->admin($admin)->get("/admin/sites/{$site->id}/edit")->assertOk()->assertSee(__('portal.generic.secret_stored'))->getContent();
        $this->assertStringNotContainsString(self::SECRET, $page);

        // Empty keeps the secret; the general site form keeps the mapping.
        $this->save($admin, ['param_ip' => 'ip'])->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, GenericSettings::secret($this->site->fresh()));
        $this->admin($admin)->put("/admin/sites/{$site->id}", ['name' => 'Site G', 'slug' => 'site-g', 'driver' => GenericRadiusDriver::KEY,
            'portal_url' => 'https://portal.example.com', 'hotspot_hosts' => 'hotspot.example.com'])->assertSessionHasNoErrors();
        $this->assertSame('ip', GenericSettings::forSite($this->site->fresh())['param_ip']);
        $this->save($admin, ['handoff_secret_clear' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(GenericSettings::secret($this->site->fresh()));

        // Validation.
        $this->save($admin, ['param_mac' => 'a b'])->assertSessionHasErrors('generic.param_mac');
        $this->save($admin, ['field_password' => str_repeat('x', 41)])->assertSessionHasErrors('generic.field_password');
        $this->save($admin, ['param_login_url' => '', 'login_url' => ''])->assertSessionHasErrors('generic.login_url');
        $this->save($admin, ['login_url' => 'ftp://hotspot.example.com/'])->assertSessionHasErrors('generic.login_url');
        $this->save($admin, ['login_url' => 'http://hotspot.example.com/'])->assertSessionHasErrors('generic.login_url');
        $this->save($admin, ['extra_fields' => implode("\n", array_map(fn ($i) => "f$i=$i", range(1, 11)))])->assertSessionHasErrors('generic.extra_fields');
        $this->save($admin, ['extra_fields' => "ok=1\nbad line"])->assertSessionHasErrors('generic.extra_fields');
        $this->save($admin, ['preset' => 'unknown'])->assertSessionHasErrors('generic.preset');
        $this->save($admin, ['disconnect_attrs' => ['NAS-Port-Id']])->assertSessionHasErrors('generic.disconnect_attrs.0');
        $this->save($admin, ['login_page_template' => 'a="b"'])->assertSessionHasErrors('generic.login_page_template');
        $this->save($admin, ['login_url' => 'http://{uamip}:{uamport}/logon', 'method' => 'GET', 'allow_http' => '1', 'param_login_url' => ''])
            ->assertSessionHasNoErrors()->assertSessionHas('warning', __('portal.generic.http_warning'));
        $this->save($admin, ['login_url' => 'http://{nas_host}/api/captiveportal/access/logon/{zone}/', 'method' => 'POST', 'allow_http' => '1',
            'param_login_url' => '', 'disconnect_attrs' => []])->assertSessionHasNoErrors();
        $this->assertSame([], GenericSettings::forSite($this->site->fresh())['disconnect_attrs']);
        $this->save($admin, ['method' => 'fragment'])->assertSessionHasNoErrors();
        $this->save($admin, ['method' => 'PUT'])->assertSessionHasErrors('generic.method');

        // Managers have no access; other drivers have no generic section.
        $manager = User::factory()->create();
        $this->admin($manager)->put("/admin/sites/{$site->id}/generic", ['generic' => self::input()])->assertForbidden();
        $mikrotik = Site::create(['slug' => 'site-m', 'name' => 'Site M', 'driver' => 'mikrotik_ros7', 'settings' => []]);
        $this->admin($admin)->put("/admin/sites/{$mikrotik->id}/generic", ['generic' => self::input()])->assertNotFound();
        $this->admin($admin)->get("/admin/sites/{$mikrotik->id}/generic/simulate")->assertNotFound();
        $this->admin($admin)->get("/admin/sites/{$mikrotik->id}/edit")->assertOk()->assertDontSee('data-site-generic', false);
    }

    public function test_apply_preset_prefills_the_form_without_saving(): void
    {
        $admin = User::factory()->admin()->create();
        $before = $this->site->fresh()->settings;

        $this->admin($admin)->post("/admin/sites/{$this->site->id}/generic/preset", ['preset' => Presets::COOVACHILLI])
            ->assertRedirect(route('admin.sites.edit', $this->site).'#generic')
            ->assertSessionHas('_old_input.generic.login_url', 'http://{uamip}:{uamport}/logon')
            ->assertSessionHas('_old_input.generic.password_encoding', 'chilli')
            ->assertSessionHas('status', __('portal.generic.preset_loaded', ['preset' => __('portal.generic.presets.coovachilli.label')]));
        $this->assertSame($before, $this->site->fresh()->settings);
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()
            ->assertSee('value="http://{uamip}:{uamport}/logon"', false)->assertSee(__('portal.generic.presets.coovachilli.note'));

        $this->admin($admin)->post("/admin/sites/{$this->site->id}/generic/preset", ['preset' => 'nope'])->assertStatus(422);
    }

    public function test_mapping_is_a_security_setting_and_the_secret_survives_a_key_rotation(): void
    {
        $this->mapping(Presets::COOVACHILLI, [], self::SECRET);
        $before = $this->site->fresh()->setting('generic.handoff_secret');

        app(FederatedSecrets::class)->rotate();

        $site = $this->site->fresh();
        $this->assertNotSame($before, $site->setting('generic.handoff_secret'));
        $this->assertSame(self::SECRET, GenericSettings::secret($site));
        $this->assertContains('generic', Federation::SECURITY_SITE_SETTINGS);
    }

    public function test_opnsense_preset_posts_to_the_logon_api_or_hands_off_through_the_fragment(): void
    {
        $this->mapping(Presets::OPNSENSE);
        $grant = $this->code();
        $this->guest()->get('/portal/g/site-g?'.http_build_query(['nas_host' => '10.1.0.1:8000', 'zone' => '0',
            'mac' => 'aa:bb:cc:00:00:01', 'ip' => '10.0.0.20', 'redirurl' => 'http://example.com/']))->assertRedirect();
        $this->guest()->get('/portal?'.http_build_query(['site' => 'site-g', 'nas_host' => '10.1.0.1:8000', 'zone' => '0',
            'mac' => 'aa:bb:cc:00:00:01', 'ip' => '10.0.0.20', 'redirurl' => 'http://example.com/']))->assertOk();
        $response = $this->guest()->post('/portal/code', $this->codeForm($grant->code))->assertOk()
            ->assertSee('action="http://10.1.0.1:8000/api/captiveportal/access/logon/0/"', false);
        $this->assertSame(['user', 'password', 'redirurl'], array_keys($response->viewData('fields')));

        // Fragment: the browser opens the template with the fields after "#".
        $this->mapping(Presets::OPNSENSE, ['method' => 'fragment', 'login_url' => 'http://{nas_host}/index.html']);
        $grant = $this->code();
        $this->guest()->get('/portal?'.http_build_query(['site' => 'site-g', 'nas_host' => '10.1.0.1:8000', 'zone' => '0',
            'mac' => 'aa:bb:cc:00:00:01', 'redirurl' => 'http://example.com/']));
        $response = $this->guest()->post('/portal/code', $this->codeForm($grant->code))->assertOk()
            ->assertSee('data-handoff-method="fragment"', false);
        preg_match('/id="handoff" href="([^"]+)"/', $response->getContent(), $m);
        $href = html_entity_decode($m[1]);
        $this->assertStringStartsWith('http://10.1.0.1:8000/index.html#user=', $href);
        parse_str((string) parse_url($href, PHP_URL_FRAGMENT), $fragment);
        $this->assertSame(['user', 'password', 'redirurl'], array_keys($fragment));
        $this->assertNull(parse_url($href, PHP_URL_QUERY));
        $this->authorize($fragment['user'], $fragment['password'], 'aa:bb:cc:00:00:01')->assertOk();
    }

    public function test_opnsense_has_no_disconnect_and_the_checklist_says_so(): void
    {
        $admin = User::factory()->admin()->create();
        $this->admin($admin)->post("/admin/sites/{$this->site->id}/generic/preset", ['preset' => Presets::OPNSENSE])
            ->assertSessionHas('_old_input.generic.disconnect_attrs', [])
            ->assertSessionHas('_old_input.generic.login_url', 'http://{nas_host}/api/captiveportal/access/logon/{zone}/');
        $this->admin($admin)->post("/admin/sites/{$this->site->id}/generic/preset", ['preset' => Presets::CISCO_WLC])
            ->assertSessionHas('_old_input.generic.disconnect_attrs', GenericSettings::DEFAULT_DISCONNECT);

        $this->mapping(Presets::OPNSENSE, ['disconnect_attrs' => []]);
        $this->assertNull($this->driver()->disconnectAttributes(RadiusAccounting::query()->make(['acct_session_id' => 'x', 'username' => 'u'])));
        $text = $this->driver()->checklist($this->site->fresh(), $this->nas);
        $this->assertStringContainsString('Not used (no attributes selected', $text);
        $this->assertStringContainsString('Session-Timeout', $text);
        $this->assertStringContainsString('browsers warn before posting a form', $text);
        $this->assertStringContainsString('OPNsense: Allowed addresses', $text);
        $this->assertStringContainsString('source address', $text);
        $this->assertStringNotContainsString('UDP port 3799', $text);
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk(); // consumes the flashed preset input
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()->assertSee('data-generic-disconnect-none', false);

        $this->mapping(Presets::OPNSENSE, ['method' => 'fragment', 'disconnect_attrs' => []]);
        $this->assertStringContainsString('docs/opnsense-captive-template/index.html', $this->driver()->checklist($this->site->fresh()));
        $this->assertFileExists(base_path('docs/opnsense-captive-template/index.html'));
    }

    // --- Helpers ------------------------------------------------------------------------------------

    private function driver(): GenericRadiusDriver
    {
        $driver = $this->site->fresh()->driver();
        $this->assertInstanceOf(GenericRadiusDriver::class, $driver);

        return $driver;
    }

    private function mapping(string $preset, array $overrides = [], ?string $secret = null): void
    {
        $this->site->update(['settings' => GenericSettings::mergeInto($this->site->fresh(),
            ['preset' => $preset] + $overrides + Presets::PRESETS[$preset] + ['wispr' => true,
                'disconnect_attrs' => Presets::DISCONNECT[$preset] ?? GenericSettings::DEFAULT_DISCONNECT], $secret)]);
        $this->site->refresh();
    }

    private function request(array $query): Request
    {
        return Request::create('/portal', 'GET', ['site' => 'site-g'] + $query);
    }

    private function parse(array $query): HotspotContext
    {
        return $this->driver()->parseRedirect($this->request($query), $this->site->fresh());
    }

    private function disconnect(RadiusAccounting $acct): ?array
    {
        return $this->site->fresh()->driver()->disconnectAttributes($acct->fresh());
    }

    private function guest(): static
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en');
    }

    private function admin(User $user): static
    {
        return $this->onZone('admin')->actingAs($user);
    }

    private function enter(array $extra = []): TestResponse
    {
        return $this->guest()->get('/portal?'.http_build_query(array_merge(['site' => 'site-g', 'client_mac' => 'aa:bb:cc:00:00:01',
            'switch_url' => 'https://hotspot.example.com/login.html', 'redirect' => 'http://example.com/'], $extra)));
    }

    private function code(int $duration = 86400): Grant
    {
        return app(GrantManager::class)->createCode(['duration_s' => $duration, 'expiry_mode' => ExpiryMode::FirstLogin]);
    }

    private function codeForm(string $code): array
    {
        return ['first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'guest@example.com', 'terms' => '1', 'code' => $code,
            'terms_version' => TermsVersion::current(app()->getLocale())?->id];
    }

    private function redeem(string $code): TestResponse
    {
        $this->enter();

        return $this->guest()->post('/portal/code', $this->codeForm($code));
    }

    private function openSession(string $sessionId, string $ip): SessionSummary
    {
        $fields = $this->redeem($this->code()->code)->viewData('fields');
        $this->authorize($fields['username'], $fields['password'], self::MAC)->assertOk();
        $this->accounting('Start', $sessionId, $fields['username'], ['Framed-IP-Address' => $ip]);

        return SessionSummary::query()->whereHas('accounting', fn ($q) => $q->where('acct_session_id', $sessionId))->sole();
    }

    private function authorize(string $user, string $password, string $mac): TestResponse
    {
        return $this->radius('authorize', ['User-Name' => $user, 'User-Password' => $password, 'Calling-Station-Id' => $mac]);
    }

    private function accounting(string $status, string $sessionId, string $user, array $extra = []): void
    {
        $this->radius('accounting', ['Acct-Status-Type' => $status, 'Acct-Session-Id' => $sessionId, 'User-Name' => $user,
            'Calling-Station-Id' => self::MAC, 'Framed-IP-Address' => '10.0.0.20', ...$extra])->assertNoContent();
    }

    /** Request in rlm_rest JSON format. */
    private function radius(string $endpoint, array $attributes): TestResponse
    {
        $attributes['FreeRADIUS-Client-Shortname'] = $this->nas->shortname();
        $body = array_map(fn ($v) => ['type' => 'string', 'value' => [(string) $v]], $attributes);

        return $this->onZone('internal')
            ->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/'.$endpoint, $body);
    }

    private function save(User $admin, array $overrides = []): TestResponse
    {
        return $this->admin($admin)->put("/admin/sites/{$this->site->id}/generic", ['generic' => array_merge(self::input(), $overrides)]);
    }

    private static function input(): array
    {
        return ['preset' => Presets::CISCO_WLC] + array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, Presets::PRESETS[Presets::CISCO_WLC])
            + ['wispr' => '1', 'disconnect_attrs' => GenericSettings::DEFAULT_DISCONNECT];
    }
}
