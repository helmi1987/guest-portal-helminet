<?php

namespace App\Federation;

use App\Auth\Oidc\OidcSettings;
use App\Models\Channel;
use App\Models\User;

/**
 * Replicated secrets encrypted with the federation data key (CONCEPT.md §10.2). After a key
 * rotation reencrypt() rewrites them with the current key as normal changes (new version, outbox
 * event), so peers receive the new ciphertexts.
 */
class FederatedSecrets
{
    /** Model class => attributes with the `federated` cast. */
    public const FIELDS = [
        User::class => ['totp_secret'],
        Channel::class => ['config'],
    ];

    public function __construct(private DataKey $keys) {}

    /** New current key and re-encryption of all secrets; returns the new key id. */
    public function rotate(): string
    {
        $kid = $this->keys->rotate();
        $this->reencrypt();

        return $kid;
    }

    /** Rewrite every value not encrypted with the current key; returns the number of rows/settings. */
    public function reencrypt(): int
    {
        $current = $this->keys->currentId();
        $count = 0;
        foreach (self::FIELDS as $class => $fields) {
            foreach ($class::withTrashed()->get() as $model) {
                $changed = false;
                foreach ($fields as $field) {
                    $raw = $model->getRawOriginal($field);
                    if ($raw !== null && DataKey::keyIdOf($raw) !== $current) {
                        $model->{$field} = $model->{$field};
                        $changed = true;
                    }
                }
                if ($changed && $model->isDirty()) {
                    $model->save();
                    $count++;
                }
            }
        }

        return $count + (OidcSettings::reencryptSecret() ? 1 : 0);
    }
}
