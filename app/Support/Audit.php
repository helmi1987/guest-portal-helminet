<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes audit log entries (CONCEPT.md §9). Actor defaults to the logged-in user, the IP to the
 * current HTTP request (none on the console, e.g. Telegram polling). Keys that look like secrets are
 * removed from `data`; callers must not pass personal data such as phone numbers.
 */
final class Audit
{
    /** Action groups shown as filter in the admin UI. */
    public const GROUPS = ['auth', 'two_factor', 'user', 'grant', 'approval', 'settings', 'channel', 'site', 'router', 'terms', 'backup', 'certificate'];

    private const SECRET_KEYS = '/pass|secret|token|totp|recovery|api_key|^code$|key$/i';

    public static function log(string $action, ?Model $subject = null, array $data = [], ?User $user = null, ?string $actor = null): AuditLog
    {
        $user ??= Auth::user();
        $request = app('request');

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'actor' => mb_substr($actor ?? $user?->name ?? (app()->runningInConsole() ? 'cli' : ''), 0, 255) ?: null,
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'data' => self::clean($data) ?: null,
            'ip' => $request->server->has('REQUEST_METHOD') ? $request->ip() : null,
        ]);
    }

    private static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $out;
    }
}
