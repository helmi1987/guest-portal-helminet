<?php

namespace Tests\Feature\Federation;

use App\Federation\PeerCertificate;
use App\Models\Instance;

/**
 * Test helpers for the federation API: self-signed peer certificates (like portal-certs) and
 * requests on the federation listener with a client certificate (FastCGI parameters set by nginx).
 */
trait FederationPeers
{
    /** @var array<string, string> instance id => certificate PEM */
    protected array $peerCerts = [];

    /** @return array{cert: string, key: string, pin: string} */
    protected function makeCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'portal-federation'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem, 'pin' => PeerCertificate::pin($certPem)];
    }

    /** Registered peer instance with a fresh certificate (PEM in $this->peerCerts). */
    protected function registerPeer(string $name = 'Peer', array $attributes = []): Instance
    {
        $certificate = $this->makeCertificate();
        $peer = Instance::create(array_merge(['name' => $name, 'federation_url' => 'https://'.strtolower($name).'.example.com:9443',
            'cert_fingerprint' => $certificate['pin']], $attributes));
        $this->peerCerts[$peer->id] = $certificate['cert'];

        return $peer;
    }

    /** Requests as arriving on the federation listener with this client certificate. */
    protected function asPeer(Instance|string|null $peer): static
    {
        $pem = $peer instanceof Instance ? $this->peerCerts[$peer->id] : $peer;

        return $this->withServerVariables(['PORTAL_ZONE' => 'federation', 'PORTAL_CLIENT_CERT' => $pem === null ? '' : rawurlencode($pem)]);
    }
}
