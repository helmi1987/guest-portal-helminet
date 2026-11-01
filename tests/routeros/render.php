<?php

/**
 * Renders every RouterOS script block of the setup assistant for a fixture site/router into .rsc files
 * (used by tests/routeros/run.sh against a RouterOS CHR). Fixed fake values only; no production data.
 *
 * Usage: php tests/routeros/render.php <out-dir> [router-ip] [portal-ip] [portal-port] [wifi-mode]
 *
 * Router state query instead of rendering (same fixture, one Wi-Fi mode): environment RQ_TYPE (rest or
 * api-ssl), RQ_HOST, RQ_PORT, RQ_CERT (PEM whose key is pinned), RQ_PASS; writes one line per object to
 * <out-dir>/state-<type>.txt ("<key> <status> <diff>") or "error <kind> <detail>", and the adopt commands of
 * objects without comment ("unmanaged") to <out-dir>/adopt-<type>.rsc.
 */

use App\Models\NasClient;
use App\Models\Site;
use App\Nas\RouterOs\RouterStateQuery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$out = $argv[1] ?? null;
if ($out === null) {
    fwrite(STDERR, "usage: php tests/routeros/render.php <out-dir> [router-ip] [portal-ip] [portal-port] [wifi-mode]\n");
    exit(2);
}
$routerIp = $argv[2] ?? '10.0.2.15';
$portalIp = $argv[3] ?? '10.0.2.2';
$portalPort = (int) ($argv[4] ?? 8443);
$wifiModes = isset($argv[5]) ? [$argv[5]] : ['capsman1', 'capsman2', 'local'];

// Isolated in-memory application: never touches the configured database.
$tmp = sys_get_temp_dir().'/routeros-render-'.getmypid();
@mkdir($tmp, 0700, true);
foreach ([
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)), 'APP_URL' => 'https://portal.example.com',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'PORTAL_DEFAULT_ZONE' => '',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'LOG_CHANNEL' => 'null',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['portal.radius.clients_file' => $tmp.'/clients.conf', 'portal.router_cert.fetch_interval' => '1d']);
Artisan::call('migrate', ['--force' => true]);

@mkdir($out, 0755, true);
// Fake test value (not a secret): quote, backslash, dollar, question mark, brackets and spaces
$wifiPassword = 'te"st\\pa$s? [w] {x};';
$portalUrl = 'https://portal.example.com'.($portalPort === 443 ? '' : ':'.$portalPort);

foreach ($wifiModes as $i => $mode) {
    $site = Site::create(['slug' => 'site-'.$mode, 'name' => 'Test site', 'driver' => 'mikrotik_ros7', 'settings' => [
        'hotspot_hosts' => ['hotspot.guest.example.com'],
        'radius_address' => $portalIp,
        'portal_address' => $portalIp,
        'portal_url' => $portalUrl,
        'setup' => [
            'vlan_id' => 30, 'bridge' => 'bridge', 'interface' => 'vlan30-guest', 'subnet' => '10.30.0.0/24',
            'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => $mode,
            // Mode "local" also covers the flash folder variant (a plain directory on the CHR).
            'html_directory' => $mode === 'local' ? 'flash/hotspot' : 'hotspot',
            // capsman2: open SSID; the others with a password using every character RouterOS escapes
            'wifi_password' => Site::encryptWifiPassword($mode === 'capsman2' ? null : $wifiPassword),
        ],
    ]]);
    // Two guest Wi-Fi masters per mode (instance-local selection): local = radios (absent on the CHR: skipped),
    // CAPsMAN = master configurations of the seed's provisioning rules (cfg-main with a slave, cfg-office without)
    $site->forceFill(['wifi_masters_local' => [$mode => $mode === 'local' ? ['wifi1', 'wifi2'] : ['cfg-main', 'cfg-office']]])->save();
    $nas = $site->nasClients()->create(['name' => 'Test router', 'nas_ip' => $routerIp, 'coa_port' => 3799]);
    $nas = NasClient::find($nas->id);
    // Fixed test values (fake, not secrets).
    $nas->radius_secret = 'testradiussecret0123456789abcdef';
    $nas->cert_token = 'testcerttoken0123456789abcdefABCD';
    // Read-only API user of the state query (name only; the password is set by run.sh)
    $nas->api_user = 'portal-ro';
    $nas->api_type = getenv('RQ_TYPE') ?: 'rest';
    $nas->save();
    $site = Site::with('nasClients')->find($site->id);
    $driver = $site->driver();

    if (getenv('RQ_TYPE')) {
        config(['portal.router_cert.cert' => getenv('RQ_CERT')]);
        $nas = NasClient::find($nas->id);
        $nas->fill(['api_host' => getenv('RQ_HOST') ?: '127.0.0.1', 'api_port' => (int) getenv('RQ_PORT'), 'api_password' => getenv('RQ_PASS')])->save();
        $nas = NasClient::with('site.nasClients')->find($nas->id);
        $error = app(RouterStateQuery::class)->run($nas);
        $lines = [];
        if ($error !== null) {
            $lines[] = "error {$error['kind']} {$error['detail']}";
        } else {
            $nas->refresh();
            $adopt = [];
            foreach ($driver->compareState($site, $nas->router_state) as $objects) {
                foreach ($objects as $key => $item) {
                    $lines[] = "{$key} {$item['status']}".($item['diff'] !== [] ? ' '.json_encode($item['diff']) : '');
                    if (($item['adopt'] ?? null) !== null) {
                        $adopt[] = $item['adopt'];
                    }
                }
            }
            // One-time adopt commands of objects without comment (as shown on the setup page)
            file_put_contents($out.'/adopt-'.getenv('RQ_TYPE').'.rsc', implode("\n", array_unique($adopt))."\n");
            $lines[] = 'version '.($nas->router_state['menus']['/system resource']['rows'][0]['version'] ?? '?');
        }
        file_put_contents($out.'/state-'.getenv('RQ_TYPE').'.txt', implode("\n", $lines)."\n");
        fwrite(STDOUT, implode("\n", $lines)."\n");

        continue;
    }

    $dir = count($wifiModes) > 1 ? $out.'/'.$mode : $out;
    @mkdir($dir, 0755, true);
    $files = [];
    foreach ($driver->setupCommands($site, [
        'veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.17.0.1', 'bridge' => 'containers',
        'disk' => 'disk1', 'image' => 'ghcr.io/owner/repo:latest',
    ]) as $step => $blocks) {
        foreach ($blocks as $block => $rsc) {
            $files["{$step}.{$block}.rsc"] = $rsc;
        }
    }
    // Display variant of the setup page (password placeholder + guard), for the paste/import guard test
    $files['network.wifi.masked.rsc'] = $driver->setupCommands($site, [
        'veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.17.0.1', 'bridge' => 'containers',
        'disk' => 'disk1', 'image' => 'ghcr.io/owner/repo:latest',
    ], false)['network']['wifi'];
    $files['router.rsc'] = $driver->configScript($site, $nas);
    $files['login_fetch.rsc'] = $driver->loginPageFetch($site, $nas);
    $files['api_access.rsc'] = $driver->apiAccessCommands($site, $nas);
    file_put_contents($dir.'/login.html', $driver->loginPage($site));
    file_put_contents($dir.'/nas_id', (string) $nas->id);
    file_put_contents($dir.'/keys', implode("\n", array_merge(...array_map('array_keys', array_values($driver->managedObjects($site)))))."\n");
    file_put_contents($dir.'/wifi_password', $mode === 'capsman2' ? '' : $wifiPassword);
    foreach ($files as $name => $rsc) {
        file_put_contents($dir.'/'.$name, rtrim($rsc)."\n");
    }
    fwrite(STDOUT, "$dir: ".implode(' ', array_keys($files))."\n");
}
