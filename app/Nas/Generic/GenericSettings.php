<?php

namespace App\Nas\Generic;

use App\Federation\DataKey;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Mapping of a generic RADIUS captive-portal site (CONCEPT.md §5.1, decision 2026-11-04, v0.28.0): the
 * replicated site setting `generic`, a security site setting (Federation::SECURITY_SITE_SETTINGS) because it
 * decides where the one-time credentials go. The optional handoff secret (e.g. the CoovaChilli uamsecret) is
 * encrypted with the federation data key like the UniFi controller secrets.
 */
final class GenericSettings
{
    public const SITE_SETTING = 'generic';

    public const SECRET = 'handoff_secret';

    /** fragment: navigation to the login URL with the fields in the #fragment (a page on the NAS posts them, e.g. OPNsense). */
    public const METHODS = ['POST', 'GET', 'fragment'];

    /** plain: the password as issued; chilli: CoovaChilli UAM PAP (hex, XOR with the challenge). */
    public const ENCODINGS = ['plain', 'chilli'];

    /** Identifying attributes a Disconnect-Request may carry (NAS-Port-Id is not recorded by the accounting). */
    public const DISCONNECT_ATTRIBUTES = ['User-Name', 'Acct-Session-Id', 'Framed-IP-Address', 'Calling-Station-Id'];

    public const DEFAULT_DISCONNECT = ['User-Name', 'Acct-Session-Id', 'Framed-IP-Address'];

    /** Redirect parameter and form field names. */
    public const NAME_RULE = 'regex:/^[A-Za-z0-9_.-]{1,40}$/';

    /** Values that may be put into a login URL template or an extra field ({name} placeholders). */
    public const PLACEHOLDER_VALUE = '/^[A-Za-z0-9.:_-]{1,253}\z/';

    public const MAX_EXTRA_FIELDS = 10;

    /** Characters of the login page template (a query string with the NAS's own variables). */
    public const TEMPLATE_RULE = 'regex:/^[A-Za-z0-9_.~$()%=&{}:\[\]-]*$/';

    public const DEFAULTS = Presets::PRESETS[Presets::CUSTOM] + [
        'preset' => Presets::CUSTOM,
        'wispr' => true,
        'disconnect_attrs' => self::DEFAULT_DISCONNECT,
    ];

    /**
     * Normalised mapping (defaults merged, no secret). Values may arrive by replication without form
     * validation: names that fail the rules are emptied, the driver checks URLs again on every use.
     *
     * @return array<string, mixed>
     */
    public static function forSite(Site $site): array
    {
        $stored = $site->setting(self::SITE_SETTING, []);
        $c = array_merge(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
        $name = fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z0-9_.-]{1,40}\z/', $v) === 1 ? $v : '';
        foreach (['param_mac', 'param_ip', 'param_login_url', 'param_orig', 'field_username', 'field_password', 'field_success'] as $key) {
            $c[$key] = $name($c[$key]);
        }
        $attrs = is_array($c['disconnect_attrs']) ? array_values(array_intersect(self::DISCONNECT_ATTRIBUTES, $c['disconnect_attrs'])) : [];

        return [
            'preset' => in_array($c['preset'], Presets::keys(), true) ? $c['preset'] : Presets::CUSTOM,
            'param_mac' => $c['param_mac'],
            'param_ip' => $c['param_ip'],
            'param_login_url' => $c['param_login_url'],
            'login_url' => is_string($c['login_url']) && strlen($c['login_url']) <= 255 ? trim($c['login_url']) : '',
            'param_orig' => $c['param_orig'],
            'method' => in_array($c['method'], self::METHODS, true) ? $c['method'] : 'POST',
            'field_username' => $c['field_username'] ?: 'username',
            'field_password' => $c['field_password'] ?: 'password',
            'field_success' => $c['field_success'],
            'extra_fields' => is_string($c['extra_fields']) ? $c['extra_fields'] : '',
            'password_encoding' => in_array($c['password_encoding'], self::ENCODINGS, true) ? $c['password_encoding'] : 'plain',
            'allow_http' => (bool) $c['allow_http'],
            'wispr' => (bool) $c['wispr'],
            'disconnect_attrs' => $attrs,
            'login_page_template' => is_string($c['login_page_template']) && preg_match('/^[A-Za-z0-9_.~$()%=&{}:\[\]-]{0,500}\z/', $c['login_page_template']) === 1
                ? $c['login_page_template'] : '',
        ];
    }

    /**
     * Extra handoff fields: one "name=value" per line, at most MAX_EXTRA_FIELDS; invalid lines are skipped.
     *
     * @return array<string, string>
     */
    public static function extraFields(string $lines): array
    {
        $fields = [];
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/^[A-Za-z0-9_.-]{1,40}\z/', $name) === 1 && preg_match('/^[\x20-\x7E]{0,255}\z/', $value) === 1) {
                $fields[$name] = $value;
            }
            if (count($fields) >= self::MAX_EXTRA_FIELDS) {
                break;
            }
        }

        return $fields;
    }

    /** Validation rules of the settings section (prefix generic.). */
    public static function rules(): array
    {
        $name = ['nullable', 'string', self::NAME_RULE];

        return [
            'generic.preset' => ['required', Rule::in(Presets::keys())],
            'generic.param_mac' => ['required', 'string', self::NAME_RULE],
            'generic.param_ip' => $name,
            'generic.param_login_url' => $name,
            'generic.login_url' => ['nullable', 'string', 'max:255', 'regex:#^https?://[A-Za-z0-9.{}_:\-\[\]]+(/[A-Za-z0-9._~/{}%=&?+\-]*)?$#'],
            'generic.param_orig' => $name,
            'generic.method' => ['required', Rule::in(self::METHODS)],
            'generic.field_username' => ['required', 'string', self::NAME_RULE],
            'generic.field_password' => ['required', 'string', self::NAME_RULE],
            'generic.field_success' => $name,
            'generic.extra_fields' => ['nullable', 'string', 'max:2000'],
            'generic.password_encoding' => ['required', Rule::in(self::ENCODINGS)],
            'generic.allow_http' => ['boolean'],
            'generic.wispr' => ['boolean'],
            'generic.disconnect_attrs' => ['nullable', 'array'],
            'generic.disconnect_attrs.*' => [Rule::in(self::DISCONNECT_ATTRIBUTES)],
            'generic.login_page_template' => ['nullable', 'string', 'max:500', self::TEMPLATE_RULE],
            'generic.handoff_secret' => ['nullable', 'string', 'max:128', 'regex:/^[\x21-\x7E]+$/'],
            'generic.handoff_secret_clear' => ['boolean'],
        ];
    }

    public static function hasSecret(Site $site): bool
    {
        return self::stored($site) !== null;
    }

    public static function secretBroken(Site $site): bool
    {
        $stored = self::stored($site);

        return $stored !== null && self::decrypt($stored) === null;
    }

    public static function secret(Site $site): ?string
    {
        $stored = self::stored($site);
        if ($stored === null) {
            return null;
        }
        $plain = self::decrypt($stored);
        if ($plain === null) {
            // Never log the value.
            Log::warning('Handoff secret of the generic site cannot be decrypted; enter it again on the site page.', ['site' => $site->slug]);
        }

        return $plain;
    }

    /**
     * Site settings with a new mapping; $secret null keeps the stored one, '' removes it.
     *
     * @return array<string, mixed>
     */
    public static function mergeInto(Site $site, array $config, #[\SensitiveParameter] ?string $secret): array
    {
        $settings = $site->settings ?? [];
        $current = is_array($settings[self::SITE_SETTING] ?? null) ? $settings[self::SITE_SETTING] : [];
        $generic = array_merge(array_intersect_key($current, self::DEFAULTS), array_intersect_key($config, self::DEFAULTS));
        $stored = $secret === null ? ($current[self::SECRET] ?? null) : ($secret === '' ? null : app(DataKey::class)->encrypt($secret));
        if (is_string($stored) && $stored !== '') {
            $generic[self::SECRET] = $stored;
        }
        $settings[self::SITE_SETTING] = $generic;

        return $settings;
    }

    /** Re-encrypt the handoff secrets with the current data key (after a rotation); number of sites rewritten. */
    public static function reencryptSecrets(): int
    {
        $count = 0;
        $current = app(DataKey::class)->currentId();
        foreach (Site::withTrashed()->get() as $site) {
            $stored = self::stored($site);
            $plain = $stored === null ? null : self::decrypt($stored);
            if ($plain === null || DataKey::keyIdOf($stored) === $current) {
                continue;
            }
            $settings = $site->settings ?? [];
            $settings[self::SITE_SETTING][self::SECRET] = app(DataKey::class)->encrypt($plain);
            $site->settings = $settings;
            $site->save();
            $count++;
        }

        return $count;
    }

    private static function stored(Site $site): ?string
    {
        $stored = $site->setting(self::SITE_SETTING.'.'.self::SECRET);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    private static function decrypt(string $stored): ?string
    {
        try {
            return app(DataKey::class)->decrypt($stored);
        } catch (Throwable) {
            return null;
        }
    }
}
