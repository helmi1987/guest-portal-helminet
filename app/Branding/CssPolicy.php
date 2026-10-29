<?php

namespace App\Branding;

/**
 * Rules for the admin's custom CSS (docs/branding.md, CONCEPT.md §9): guest pages must not load
 * anything from other hosts (privacy; the walled garden would block it anyway). Rejected: @import,
 * url()/src()/image-set() targets with a scheme other than data: or protocol-relative ("//host"),
 * absolute URLs in strings, legacy script hooks (expression(), behavior, -moz-binding,
 * javascript:). Same-origin paths ("/x.png") and data: URLs are allowed. Checks run on the text
 * with comments removed and CSS escapes decoded, so "u\72l(" is treated like "url(".
 */
final class CssPolicy
{
    /** Schemes a URL parser resolves to another host even without "//" (e.g. "https:host/x"). */
    private const SPECIAL_SCHEME = '/^(https?|ftp|wss?|file):/';

    /** url(...) / src(...) with a double-quoted, single-quoted or unquoted target. */
    private const URL_PATTERN = '/\b(?:url|src)\s*\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)"\']*))\s*\)?/i';

    /** Error key (lang portal.branding.errors.*) or null when the CSS is acceptable. */
    public static function violation(string $css): ?string
    {
        if (! mb_check_encoding($css, 'UTF-8') || str_contains($css, "\0")) {
            return 'css_invalid';
        }
        $text = strtolower(self::decode($css));

        if (str_contains($text, '@import')) {
            return 'css_import';
        }
        if (preg_match('/expression\s*\(|behavior\s*:|-moz-binding|javascript:|vbscript:/', $text)) {
            return 'css_unsafe';
        }
        foreach (self::urls($text) as $url) {
            if (! self::isLocal(self::normalize($url))) {
                return 'css_external';
            }
        }
        // Strings (e.g. image-set("https://...")): tokenized on the raw text so escaped quotes keep the
        // string boundaries, then decoded and normalized like a URL parser would.
        $raw = (string) preg_replace('#/\*.*?(\*/|$)#s', '', $css);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/s', $raw, $m, PREG_SET_ORDER);
        foreach ($m as $string) {
            $value = self::normalize(self::decode(($string[1] ?? '').($string[2] ?? '')));
            if (! str_starts_with($value, 'data:') && (str_contains($value, '//') || preg_match(self::SPECIAL_SCHEME, $value))) {
                return 'css_external';
            }
        }

        return null;
    }

    /** Text without comments and with CSS escapes (\72, \u) decoded; escaped newlines are line continuations. */
    public static function decode(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?(\*/|$)#s', '', $css);
        $css = (string) preg_replace('/\\\\(\r\n|\n|\r|\f)/', '', $css);

        return (string) preg_replace_callback('/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/su', function (array $m): string {
            if (($m[1] ?? '') !== '') {
                $code = hexdec($m[1]);

                return $code > 0 && $code <= 0x10FFFF ? mb_chr((int) $code, 'UTF-8') : '';
            }

            return $m[2] ?? '';
        }, $css);
    }

    /** As a URL parser sees it: no whitespace/control characters, lower case, backslash = slash. */
    private static function normalize(string $value): string
    {
        return str_replace('\\', '/', strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $value)));
    }

    /** data: URL or a same-origin path (no scheme, not protocol-relative). */
    private static function isLocal(string $url): bool
    {
        return str_starts_with($url, 'data:') || (! str_starts_with($url, '//') && ! preg_match('/^[a-z][a-z0-9+.-]*:/', $url));
    }

    /**
     * Targets of url(...) and src(...) (quoted or not).
     *
     * @return list<string>
     */
    public static function urls(string $css): array
    {
        preg_match_all(self::URL_PATTERN, $css, $m, PREG_SET_ORDER);

        return array_map(fn (array $x) => trim(($x[1] ?? '').($x[2] ?? '').($x[3] ?? '')), $m);
    }
}
