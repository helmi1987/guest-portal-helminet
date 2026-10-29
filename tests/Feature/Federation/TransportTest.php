<?php

namespace Tests\Feature\Federation;

use App\Federation\Federation;
use App\Federation\PeerCertificate;
use App\Federation\PeerClient;
use App\Federation\SigningKey;
use App\Models\Instance;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Federation part D: federation listener (zone, client certificate pin), peer HTTP client, identity.
 */
class TransportTest extends TestCase
{
    use FederationPeers, RefreshDatabase;

    public function test_pin_is_the_spki_sha256_in_curl_format(): void
    {
        $certificate = $this->makeCertificate();
        $public = openssl_pkey_get_details(openssl_pkey_get_public($certificate['cert']))['key'];
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $public));

        $this->assertSame('sha256//'.base64_encode(hash('sha256', $der, true)), $certificate['pin']);
        $this->assertTrue(PeerCertificate::isPin($certificate['pin']));
        $this->assertNull(PeerCertificate::pin('not a certificate'));
        $this->assertNull(PeerCertificate::pin(null));
    }

    public function test_federation_routes_need_a_registered_client_certificate(): void
    {
        $peer = $this->registerPeer('Hub');

        $this->asPeer(null)->getJson('/federation/status')->assertForbidden();
        $this->asPeer($this->makeCertificate()['cert'])->getJson('/federation/status')->assertForbidden()
            ->assertJson(['error' => 'unknown peer']);
        $this->asPeer($peer)->getJson('/federation/status')->assertOk()
            ->assertJson(['instance_id' => app(LocalInstance::class)->id(), 'protocol' => Federation::FEDERATION_PROTOCOL]);

        // A removed peer (tombstone) is no longer trusted.
        $peer->delete();
        $this->asPeer($peer)->getJson('/federation/status')->assertForbidden();
    }

    public function test_federation_routes_exist_only_on_the_federation_listener(): void
    {
        $peer = $this->registerPeer('Hub');
        $pem = rawurlencode($this->peerCerts[$peer->id]);

        foreach (['guest', 'admin', 'internal'] as $zone) {
            $this->withServerVariables(['PORTAL_ZONE' => $zone, 'PORTAL_CLIENT_CERT' => $pem])
                ->getJson('/federation/status')->assertNotFound();
        }
        // Guest and admin routes do not answer on the federation listener.
        $this->asPeer($peer)->get('/portal')->assertNotFound();
        $this->asPeer($peer)->get('/admin/login')->assertNotFound();
    }

    public function test_peer_client_pins_the_key_sends_identity_and_does_not_follow_redirects(): void
    {
        $peer = $this->registerPeer('Hub');
        Http::fake(['hub.example.com:9443/*' => Http::response(['ok' => true])]);

        $pending = app(PeerClient::class)->to($peer);
        $options = $pending->getOptions();
        $this->assertFalse($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertLessThanOrEqual(10, $options['timeout']);

        $pending->get('/federation/status');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hub.example.com:9443/federation/status'
            && $r->header('X-Federation-Instance')[0] === app(LocalInstance::class)->id()
            && $r->header('X-Federation-Protocol')[0] === (string) Federation::FEDERATION_PROTOCOL);

        $this->expectException(\InvalidArgumentException::class);
        app(PeerClient::class)->pinned('http://hub.example.com:9443', $peer->cert_fingerprint);
    }

    public function test_instance_publishes_signing_key_and_certificate_pin(): void
    {
        $dir = sys_get_temp_dir().'/portal-test-'.getmypid().'/federation-'.bin2hex(random_bytes(3));
        mkdir($dir, 0700, true);
        $certificate = $this->makeCertificate();
        file_put_contents("$dir/federation.crt", $certificate['cert']);
        config(['portal.federation.dir' => $dir, 'portal.federation.url' => 'https://10.0.0.2:9443']);

        Artisan::call('portal:init');

        $instance = Instance::find(app(LocalInstance::class)->id());
        $this->assertSame($certificate['pin'], $instance->cert_fingerprint);
        $this->assertSame('https://10.0.0.2:9443', $instance->federation_url);
        $this->assertSame((new SigningKey)->publicKey(), $instance->signing_public_key);
        $this->assertTrue(SigningKey::verify('message', (new SigningKey)->sign('message'), $instance->signing_public_key));
        $this->assertFalse(SigningKey::verify('other', (new SigningKey)->sign('message'), $instance->signing_public_key));

        // A federation URL set in the UI is kept on restart.
        $instance->update(['federation_url' => 'https://vpn.example.com:9443']);
        Artisan::call('portal:init');
        $this->assertSame('https://vpn.example.com:9443', $instance->fresh()->federation_url);
    }
}
