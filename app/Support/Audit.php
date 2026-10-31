<?php

namespace App\Support;

use App\Federation\Resync;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes audit log entries (CONCEPT.md §9). Actor defaults to the logged-in user, the IP to the
 * current HTTP request (none on the console, e.g. Telegram polling). Keys that look like secrets are
 * removed from `data`; callers must not pass personal data such as phone numbers.
 *
 * audit_logs is replicated: while the restore resync blocks replicated writes (CONCEPT.md §10.3) the
 * entry goes to the application log instead (no row, no event), so e.g. admin login and logout keep
 * working; a row with a reused own sequence number would collide with the events being recovered.
 */
final class Audit
{
    /** Action groups shown as filter in the admin UI. */
    public const GROUPS = ['auth', 'two_factor', 'user', 'grant', 'site_code', 'whitelist', 'approval', 'settings', 'channel', 'site', 'router', 'terms', 'backup', 'certificate', 'federation'];

    private const SECRET_KEYS = '/pass|secret|token|totp|recovery|api_key|^code$|key$/i';

    /** @return AuditLog|null null while the restore resync blocks replicated writes (logged instead) */
    public static function log(string $action, ?Model $subject = null, array $data = [], ?User $user = null, ?string $actor = null): ?AuditLog
    {
        $user ??= Auth::user();
        $request = app('request');
        $entry = [
            'user_id' => $user?->getKey(),
            'actor' => mb_substr($actor ?? $user?->name ?? (app()->runningInConsole() ? 'cli' : ''), 0, 255) ?: null,
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'data' => self::clean($data) ?: null,
            'ip' => $request->server->has('REQUEST_METHOD') ? $request->ip() : null,
        ];
        if (Resync::required()) {
            Log::warning('Audit entry not stored in the audit log (federation resync in progress)', $entry);

            return null;
        }

        return AuditLog::create($entry);
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
