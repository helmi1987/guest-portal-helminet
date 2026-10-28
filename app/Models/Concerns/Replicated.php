<?php

namespace App\Models\Concerns;

use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rows that will be replicated between federated instances (CONCEPT.md §10.3, §11):
 * ULID key, origin instance, HLC version, soft delete as tombstone.
 */
trait Replicated
{
    use HasUlids, SoftDeletes;

    public static function bootReplicated(): void
    {
        static::creating(function ($model) {
            $model->origin_instance_id ??= app(LocalInstance::class)->id();
        });

        static::saving(function ($model) {
            $model->version = app(Hlc::class)->now();
        });

        static::deleting(function ($model) {
            // Soft delete (tombstone) must carry a new version as well.
            if (! $model->isForceDeleting()) {
                $model->version = app(Hlc::class)->now();
            }
        });
    }
}
