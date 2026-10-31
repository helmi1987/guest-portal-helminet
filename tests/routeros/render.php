<?php

/**
 * Renders every RouterOS script block of the setup assistant for a fixture site/router into .rsc files
 * (used by tests/routeros/run.sh against a RouterOS CHR). Fixed fake values only; no production data.
 *
 * Usage: php tests/routeros/render.php <out-dir> [router-ip] [portal-ip] [portal-port] [wifi-mode]
 */

use App\Models\NasClient;
use App\Models\Site;
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
    $nas = $site->nasClients()->create(['name' => 'Test router', 'nas_ip' => $routerIp, 'coa_port' => 3799]);
    $nas = NasClient::find($nas->id);
    // Fixed test values (fake, not secrets).
    $nas->radius_secret = 'testradiussecret0123456789abcdef';
    $nas->cert_token = 'testcerttoken0123456789abcdefABCD';
    $nas->save();
    $site = Site::with('nasClients')->find($site->id);
    $driver = $site->driver();

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
    $files['router.rsc'] = $driver->configScript($site, $nas);
    $files['login_fetch.rsc'] = $driver->loginPageFetch($site, $nas);
    file_put_contents($dir.'/login.html', $driver->loginPage($site));
    file_put_contents($dir.'/nas_id', (string) $nas->id);
    file_put_contents($dir.'/keys', implode("\n", array_merge(...array_map('array_keys', array_values($driver->managedObjects($site)))))."\n");
    file_put_contents($dir.'/wifi_password', $mode === 'capsman2' ? '' : $wifiPassword);
    foreach ($files as $name => $rsc) {
        file_put_contents($dir.'/'.$name, rtrim($rsc)."\n");
    }
    fwrite(STDOUT, "$dir: ".implode(' ', array_keys($files))."\n");
}
