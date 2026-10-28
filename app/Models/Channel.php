<?php

namespace App\Models;

use App\Approval\Channels\NotificationChannel;
use App\Approval\Channels\NotificationChannels;
use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification channel (replicated, CONCEPT.md §7.3, §11). Config including secrets is encrypted.
 */
#[Fillable(['kind', 'driver', 'name', 'config', 'enabled'])]
class Channel extends Model
{
    use Replicated;

    public const KIND_NOTIFICATION = 'notification';

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'enabled' => 'boolean'];
    }

    public function scopeActiveNotification(Builder $query): void
    {
        $query->where('kind', self::KIND_NOTIFICATION)->where('enabled', true);
    }

    public function conf(string $key, mixed $default = null): mixed
    {
        $value = $this->config[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function driver(): NotificationChannel
    {
        return NotificationChannels::make($this->driver);
    }
}
