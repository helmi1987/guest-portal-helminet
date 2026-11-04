<?php

namespace App\Nas\Unifi;

/**
 * Connection settings of one site's UniFi controller with the decrypted secrets (UnifiSettings::config()).
 * Never logged, serialized or put into exception messages.
 */
final readonly class UnifiConfig
{
    public function __construct(
        public string $url,
        public string $mode,
        #[\SensitiveParameter] public string $apiKey,
        public string $username,
        #[\SensitiveParameter] public string $password,
        public string $unifiSite,
        public bool $verifyTls,
        public string $tlsPin,
    ) {}

    /** Secrets to mask wherever they could appear in a message (Redact::secrets()). */
    public function secrets(): array
    {
        return [$this->apiKey, $this->password];
    }

    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'mode' => $this->mode, 'unifiSite' => $this->unifiSite];
    }
}
