<?php

namespace App\Federation;

use App\Models\LocalSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Federation data key ring (CONCEPT.md §10.2): shared by all instances, encrypts replicated secrets
 * (cast `federated`). Stored in local_settings, each key encrypted with the local APP_KEY. Values are
 * prefixed with the key id; reading accepts every known key, writing uses the current one. The first
 * key is generated on first use; pairing (later part) replaces the ring with the federation's.
 */
class DataKey
{
    public const SETTING = 'federation_data_keys';

    public const PREFIX = 'fdk1:';

    private const CIPHER = 'aes-256-gcm';

    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    public function encrypt(string $plain): string
    {
        $kid = $this->currentId();

        return self::PREFIX.$kid.':'.$this->encrypter($kid)->encryptString($plain);
    }

    /**
     * Values without the prefix are legacy APP_KEY ciphertexts (before M7) and are still readable.
     *
     * @throws DecryptException
     */
    public function decrypt(string $value): string
    {
        if (! str_starts_with($value, self::PREFIX)) {
            return Crypt::decryptString($value);
        }
        $rest = substr($value, strlen(self::PREFIX));
        $pos = strpos($rest, ':');
        if ($pos === false) {
            throw new DecryptException('Malformed federated value.');
        }

        return $this->encrypter(substr($rest, 0, $pos))->decryptString(substr($rest, $pos + 1));
    }

    /** Key id of an encrypted value (null: legacy APP_KEY value). */
    public static function keyIdOf(?string $value): ?string
    {
        if ($value === null || ! str_starts_with($value, self::PREFIX)) {
            return null;
        }

        return strstr(substr($value, strlen(self::PREFIX)), ':', true) ?: null;
    }

    public function currentId(): string
    {
        $ring = $this->ring();
        if ($ring['current'] === null) {
            return $this->rotate();
        }

        return $ring['current'];
    }

    /** New key becomes current; older keys stay readable. Returns the new key id. */
    public function rotate(): string
    {
        $kid = bin2hex(random_bytes(4));
        $this->import($kid, random_bytes(32), makeCurrent: true);

        return $kid;
    }

    /** Add a key (e.g. received from a peer), optionally as the current one. */
    public function import(string $kid, string $key, bool $makeCurrent = false): void
    {
        if (! preg_match('/^[0-9a-f]{8}$/', $kid) || strlen($key) !== 32) {
            throw new \InvalidArgumentException('Invalid data key.');
        }
        $ring = $this->ring();
        $ring['keys'][$kid] = Crypt::encryptString(base64_encode($key));
        if ($makeCurrent || $ring['current'] === null) {
            $ring['current'] = $kid;
        }
        LocalSetting::put(self::SETTING, $ring);
        unset($this->encrypters[$kid]);
    }

    /** @return list<string> */
    public function knownIds(): array
    {
        return array_keys($this->ring()['keys']);
    }

    /** Raw key bytes (for distribution to peers in a later part). */
    public function key(string $kid): string
    {
        $stored = $this->ring()['keys'][$kid] ?? null;
        if ($stored === null) {
            throw new DecryptException("Unknown federation data key $kid.");
        }

        return base64_decode(Crypt::decryptString($stored), true);
    }

    private function encrypter(string $kid): Encrypter
    {
        return $this->encrypters[$kid] ??= new Encrypter($this->key($kid), self::CIPHER);
    }

    /** @return array{current: ?string, keys: array<string, string>} */
    private function ring(): array
    {
        $ring = LocalSetting::get(self::SETTING);

        return ['current' => $ring['current'] ?? null, 'keys' => $ring['keys'] ?? []];
    }
}
