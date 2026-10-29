<?php

namespace App\Federation;

use App\Models\LocalSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Federation data key ring (CONCEPT.md §10.2): shared by all instances, encrypts replicated secrets
 * (cast `federated`). Stored in local_settings, each key encrypted with the local APP_KEY. Values are
 * prefixed with the key id; reading accepts every known key, writing uses the current one. The first
 * key is created by a migration (fallback: first use, under the write lock); pairing (later part)
 * imports the federation's key. Ring changes are read-check-write inside one transaction.
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

    /** Current key id; creates the first key if the ring is empty (normally done by the migration). */
    public function currentId(): string
    {
        $current = $this->ring()['current'];
        if ($current !== null) {
            return $current;
        }

        // Read-check-write under the write lock: concurrent first use must not create two keys.
        return DB::transaction(fn () => $this->ring()['current'] ?? $this->rotate());
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
        DB::transaction(function () use ($kid, $key, $makeCurrent) {
            $ring = $this->ring();
            $ring['keys'][$kid] = Crypt::encryptString(base64_encode($key));
            if ($makeCurrent || $ring['current'] === null) {
                $ring['current'] = $kid;
            }
            LocalSetting::put(self::SETTING, $ring);
        });
        unset($this->encrypters[$kid]);
    }

    /** @return list<string> */
    public function knownIds(): array
    {
        return array_keys($this->ring()['keys']);
    }

    /** Raw key bytes (distribution to peers). */
    public function key(string $kid): string
    {
        $stored = $this->ring()['keys'][$kid] ?? null;
        if ($stored === null) {
            throw new DecryptException("Unknown federation data key $kid.");
        }

        return base64_decode(Crypt::decryptString($stored), true);
    }

    /**
     * Whole key ring for a peer (pairing, heartbeat): raw keys base64-encoded. Only sent over the
     * pinned mTLS channel to registered peers.
     *
     * @return array{current: string, keys: array<string, string>}
     */
    public function export(): array
    {
        $current = $this->currentId();
        $keys = [];
        foreach ($this->knownIds() as $kid) {
            $keys[$kid] = base64_encode($this->key($kid));
        }

        return ['current' => $current, 'keys' => $keys];
    }

    /**
     * Add the unknown keys of a peer's ring. The peer's current key becomes current here when it was
     * unknown (a rotation elsewhere); with $adoptCurrent (pairing) always.
     *
     * @param  array{current?: mixed, keys?: mixed}  $ring
     * @return int number of keys added
     */
    public function importRing(array $ring, bool $adoptCurrent = false): int
    {
        $known = $this->knownIds();
        $current = is_string($ring['current'] ?? null) ? $ring['current'] : null;
        $added = 0;
        foreach (is_array($ring['keys'] ?? null) ? $ring['keys'] : [] as $kid => $key) {
            $raw = is_string($key) ? base64_decode($key, true) : false;
            if (! is_string($kid) || $raw === false || ! preg_match('/^[0-9a-f]{8}$/', $kid) || strlen($raw) !== 32) {
                continue;
            }
            $new = ! in_array($kid, $known, true);
            if ($new || ($adoptCurrent && $kid === $current)) {
                $this->import($kid, $raw, makeCurrent: $kid === $current);
                $added += $new ? 1 : 0;
            }
        }

        return $added;
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
