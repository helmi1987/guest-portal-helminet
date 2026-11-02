<?php

namespace App\Models\Concerns;

use App\Federation\Federation;
use App\Federation\Outbox;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rows that are (or could be) replicated between federated instances (CONCEPT.md §10.3, §11):
 * ULID key, origin instance, HLC version, soft delete as tombstone.
 *
 * For tables listed in App\Federation\Federation::TABLES every write records an outbox event in
 * the same transaction: saved (create, update, restore) and deleted (tombstone). A force delete is
 * a local purge and records nothing. Query-builder writes bypass this; use models or record the
 * row with App\Federation\Outbox::record() inside the same transaction.
 */
trait Replicated
{
    use HasUlids, SoftDeletes;

    /** @var \WeakMap<object, bool>|null models whose current save() is a real write */
    private static ?\WeakMap $pendingEvent = null;

    public static function bootReplicated(): void
    {
        self::$pendingEvent ??= new \WeakMap;

        static::creating(function ($model) {
            $model->origin_instance_id ??= app(LocalInstance::class)->id();
        });

        // Federated tables only: a new version (HLC, reads the stored clock state) and an outbox event
        // per real write. Local tables with replication columns pay nothing (accounting hot path).
        // A save that changes only local columns (Federation::LOCAL_COLUMNS) is no replicated write: a new
        // version would win last-write-wins against a remote edit not yet received here. Its updated_at still
        // moves locally (the setup assistant compares it with the router state age); the next real write carries it.
        static::saving(function ($model) {
            $write = $model->isFederated() && (! $model->exists || $model->hasReplicatedChanges());
            self::$pendingEvent[$model] = $write;
            if ($write) {
                $model->version = app(Hlc::class)->now();
            }
        });

        static::deleting(function ($model) {
            // Soft delete (tombstone) must carry a new version as well (written by runSoftDelete()).
            if ($model->isFederated() && ! $model->isForceDeleting()) {
                $model->version = app(Hlc::class)->now();
            }
        });

        static::saved(function ($model) {
            // No event for a save without changes.
            if (self::$pendingEvent[$model] ?? false) {
                $model->recordFederationEvent();
            }
            unset(self::$pendingEvent[$model]);
        });

        static::deleted(function ($model) {
            if (! $model->isForceDeleting()) {
                $model->recordFederationEvent();
            }
        });
    }

    /** Whether writes of this model are replicated (outbox events). */
    public function isFederated(): bool
    {
        return Federation::isReplicated($this->getTable());
    }

    /** Whether a column other than the local ones (Federation::LOCAL_COLUMNS) is dirty. */
    public function hasReplicatedChanges(): bool
    {
        return array_diff(array_keys($this->getDirty()), Federation::localColumns($this->getTable())) !== [];
    }

    public function save(array $options = [])
    {
        if (! $this->isFederated()) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        if (! $this->isFederated()) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    /** Tombstone including the new version (SoftDeletes writes deleted_at and updated_at only). */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());
        $time = $this->freshTimestamp();
        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time), 'version' => $this->version];
        $this->{$this->getDeletedAtColumn()} = $time;
        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }
        $query->update($columns);
        $this->syncOriginalAttributes(array_keys($columns));
        $this->fireModelEvent('trashed', false);
    }

    /** increment()/decrement() on a replicated model go through save() (new version + event). */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (! $this->exists || ! $this->isFederated()) {
            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        }
        $this->{$column} = $this->{$column} + ($method === 'increment' ? $amount : $amount * -1);
        $this->forceFill($extra);

        return $this->save();
    }

    protected function recordFederationEvent(): void
    {
        if ($this->isFederated()) {
            app(Outbox::class)->record($this->getTable(), (string) $this->getKey());
        }
    }
}
