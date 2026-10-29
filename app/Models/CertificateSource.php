<?php

namespace App\Models;

use App\Casts\Federated;
use App\Models\Concerns\Replicated;
use App\Support\DeterministicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Certificate source of one instance and target (CONCEPT.md §6, §10.3): replicated so a replacement
 * instance can take it over; each instance only pulls with its own. The API key uses the federation
 * data key. The id is derived from instance and target (instances never create two).
 */
#[Fillable(['instance_id', 'target', 'type', 'url', 'name', 'ca_pem', 'api_key'])]
#[Hidden(['api_key'])]
class CertificateSource extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['api_key' => Federated::class];
    }

    public static function idFor(string $instanceId, string $target): string
    {
        return DeterministicId::for("certificate_source:$instanceId:$target");
    }

    public function newUniqueId(): string
    {
        return self::idFor((string) $this->instance_id, (string) $this->target);
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class)->withTrashed();
    }
}
