<?php

namespace App\Models;

use App\Approval\Channels\NotificationChannel;
use App\Approval\Channels\NotificationChannels;
use App\Casts\Federated;
use App\Models\Concerns\Replicated;
use App\Sms\Drivers\SmsDriver;
use App\Sms\Drivers\SmsDrivers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification channel or SMS provider (replicated, CONCEPT.md §7.3, §11). Config including secrets is
 * encrypted with the federation data key (cast `federated`).
 */
#[Fillable(['kind', 'driver', 'name', 'config', 'enabled'])]
class Channel extends Model
{
    use Replicated;

    public const KIND_NOTIFICATION = 'notification';

    public const KIND_SMS = 'sms';

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => Federated::class.':array', 'enabled' => 'boolean'];
    }

    public function scopeActiveNotification(Builder $query): void
    {
        $query->where('kind', self::KIND_NOTIFICATION)->where('enabled', true);
    }

    /** Enabled SMS channels that may send guest codes (test-only drivers such as Free Mobile excluded). */
    public function scopeActiveSms(Builder $query): void
    {
        $query->where('kind', self::KIND_SMS)->where('enabled', true)->whereNotIn('driver', SmsDrivers::TEST_ONLY);
    }

    /** Kind of a driver name (driver names are unique across kinds), null if unknown. */
    public static function kindOf(?string $driver): ?string
    {
        return match (true) {
            isset(NotificationChannels::DRIVERS[$driver]) => self::KIND_NOTIFICATION,
            isset(SmsDrivers::DRIVERS[$driver]) => self::KIND_SMS,
            default => null,
        };
    }

    /** @return array<string, array{secret?: bool, required?: bool}> */
    public static function fieldsOf(string $driver): array
    {
        return match (self::kindOf($driver)) {
            self::KIND_NOTIFICATION => NotificationChannels::DRIVERS[$driver]::fields(),
            self::KIND_SMS => SmsDrivers::DRIVERS[$driver]::fields(),
            default => [],
        };
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

    /**
     * Stored secret values and endpoint URLs (which may carry tokens in path or query), for masking
     * in error messages; a URL is listed whole and as path + query.
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        $values = [];
        foreach (self::fieldsOf($this->driver) as $field => $spec) {
            $value = $this->conf($field);
            if (! is_string($value) || ! (($spec['secret'] ?? false) || in_array($field, ['url', 'api_url'], true))) {
                continue;
            }
            $values[] = $value;
            $parts = parse_url($value);
            if (! ($spec['secret'] ?? false) && is_array($parts)) {
                $tail = ltrim($parts['path'] ?? '', '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
                if ($tail !== '') {
                    $values[] = $tail;
                }
            }
        }

        return $values;
    }

    public function smsDriver(): SmsDriver
    {
        return SmsDrivers::make($this->driver);
    }
}
