<?php

namespace App\Federation;

use App\Models\LocalSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Ed25519 key pair of this instance for event signatures (CONCEPT.md §10.2). The secret key stays
 * local (local_settings, encrypted with APP_KEY, never replicated); the public key is published in
 * instances.signing_public_key (replicated).
 */
class SigningKey
{
    public const SETTING = 'federation_signing_key';

    private ?string $secret = null;

    /** Base64 public key; creates the key pair on first use. */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey()));
    }

    /** Base64 detached signature. */
    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey()));
    }

    public static function verify(string $message, ?string $signature, ?string $publicKey): bool
    {
        $sig = is_string($signature) ? base64_decode($signature, true) : false;
        $key = is_string($publicKey) ? base64_decode($publicKey, true) : false;
        if ($sig === false || $key === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    public static function isPublicKey(?string $value): bool
    {
        $raw = is_string($value) ? base64_decode($value, true) : false;

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    private function secretKey(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        // Read-check-write under the write lock: concurrent first use must not create two key pairs.
        $stored = LocalSetting::get(self::SETTING) ?? DB::transaction(function () {
            $stored = LocalSetting::get(self::SETTING);
            if ($stored === null) {
                $pair = sodium_crypto_sign_keypair();
                $stored = Crypt::encryptString(base64_encode(sodium_crypto_sign_secretkey($pair)));
                LocalSetting::put(self::SETTING, $stored);
            }

            return $stored;
        });

        return $this->secret = base64_decode(Crypt::decryptString($stored), true);
    }
}
