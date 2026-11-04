<?php

namespace App\Nas;

use App\Models\Site;
use Illuminate\Http\Request;

/** Helpers around ReadsRedirectSignals (decision 2026-11-04, v0.28.0). */
final class RedirectSignals
{
    public const NONE = ['has_login_url' => false, 'prefill_code' => null, 'login_result' => null];

    public const LOGIN_RESULTS = ['success', 'failed'];

    /** @return array{has_login_url: bool, prefill_code: ?string, login_result: ?string} */
    public static function of(NasDriver $driver, Request $request, Site $site): array
    {
        if (! $driver instanceof ReadsRedirectSignals) {
            return self::NONE;
        }
        $signals = $driver->redirectSignals($request, $site);
        $code = $signals['prefill_code'] ?? null;
        $result = $signals['login_result'] ?? null;

        return ['has_login_url' => ($signals['has_login_url'] ?? false) === true,
            'prefill_code' => is_string($code) && preg_match('/^\d{6}\z/', $code) === 1 ? $code : null,
            'login_result' => in_array($result, self::LOGIN_RESULTS, true) ? $result : null];
    }

    /**
     * 6-digit code of a portal URL with ?code= (path ending in /portal), e.g. the original URL of a
     * hotspot redirect after a guest scanned a notice QR code; null for any other URL.
     */
    public static function codeFromUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || ! str_ends_with((string) parse_url($url, PHP_URL_PATH), '/portal')) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $code = is_string($query['code'] ?? null) ? preg_replace('/\D/', '', $query['code']) : '';

        return strlen($code) === 6 ? $code : null;
    }
}
