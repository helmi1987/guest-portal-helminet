<?php

namespace App\Support;

use App\Federation\PeerCertificate;
use App\Federation\SigningKey;
use App\Models\Instance;
use App\Models\LocalSetting;
use Illuminate\Support\Str;

/**
 * Identity of this instance. Generated once and stored in local (non-replicated) settings. The
 * instance row (replicated) carries what peers need: federation URL, certificate pin and the public
 * key for event signatures (CONCEPT.md §10.2).
 */
class LocalInstance
{
    private ?string $id = null;

    public function id(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $id = LocalSetting::get('instance_id');

        if ($id === null) {
            $id = (string) Str::ulid();
            LocalSetting::put('instance_id', $id);

            $instance = new Instance(['name' => config('portal.instance_name')]);
            $instance->forceFill(['id' => $id, 'origin_instance_id' => $id] + $this->identity())->save();
        }

        return $this->id = $id;
    }

    public function model(): ?Instance
    {
        return Instance::query()->find($this->id());
    }

    /**
     * Bring the instance row in line with the local key material (container start): certificate pin
     * and signing key; the federation URL from the environment only while none is set. Writes (and
     * replicates) only on a change.
     */
    public function refreshIdentity(): Instance
    {
        $instance = Instance::withTrashed()->findOrFail($this->id());
        $identity = $this->identity();
        if ($instance->federation_url) {
            unset($identity['federation_url']);
        }
        $instance->forceFill(array_filter($identity, fn ($v) => $v !== null))->save();

        return $instance;
    }

    /** @return array{signing_public_key: string, cert_fingerprint: ?string, federation_url: ?string} */
    private function identity(): array
    {
        return [
            'signing_public_key' => (new SigningKey)->publicKey(),
            'cert_fingerprint' => PeerCertificate::localPin(),
            'federation_url' => config('portal.federation.url') ?: null,
        ];
    }
}
