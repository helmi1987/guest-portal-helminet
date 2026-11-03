<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Directory\DirectoryLogin;
use App\Auth\Directory\DirectorySettings;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Directory of a site for the account login (CONCEPT.md §7.2 E, §9, decision 2026-11-03, v0.24.0; admin
 * only): settings section on the site page and its check button. The service account password is
 * encrypted with the federation data key, masked, never logged, audited or flashed back as old input;
 * an empty field keeps it, "remove" deletes it.
 */
class SiteDirectoryController extends Controller
{
    /** Host name or IP address (no scheme, port or spaces: ldap_connect would read a list of URIs). */
    public const HOST_PATTERN = '/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z|^[0-9a-fA-F:]+\z/';

    /** Loose DN syntax: attribute=value pairs separated by commas (escaped commas allowed in values). */
    public const DN_PATTERN = '/^ *[A-Za-z0-9.-]+ *= *(?:\\\\.|[^,\\\\\x00-\x1F])+(?:, *[A-Za-z0-9.-]+ *= *(?:\\\\.|[^,\\\\\x00-\x1F])+)* *\z/';

    /** Attribute name (RFC 4512 descr). */
    public const ATTRIBUTE_PATTERN = '/^[A-Za-z][A-Za-z0-9-]{0,63}$/';

    public function update(Request $request, Site $site): RedirectResponse
    {
        $enabled = $request->boolean('directory.enabled');
        $hasPassword = DirectorySettings::hasPassword($site) && ! $request->boolean('directory.bind_password_clear');
        $validator = Validator::make($request->all(), [
            'directory.enabled' => ['boolean'],
            'directory.host' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:253', 'regex:'.self::HOST_PATTERN],
            'directory.port' => ['required', 'integer', 'between:1,65535'],
            'directory.tls' => ['required', Rule::in(DirectorySettings::TLS_MODES)],
            'directory.verify' => ['boolean'],
            'directory.ca_cert' => ['nullable', 'string', 'max:20000'],
            'directory.base_dn' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:500', 'regex:'.self::DN_PATTERN],
            'directory.bind_dn' => ['nullable', 'string', 'max:500', 'regex:'.self::DN_PATTERN],
            'directory.bind_password' => ['nullable', 'string', 'max:256', 'regex:/^[^\x00-\x1F\x7F]+$/'],
            'directory.bind_password_clear' => ['boolean'],
            'directory.user_filter' => ['required', 'string', 'max:500'],
            'directory.group_dn' => ['nullable', 'string', 'max:500', 'regex:'.self::DN_PATTERN],
            'directory.name_attributes' => ['nullable', 'string', 'max:200'],
            'directory.email_attribute' => ['nullable', 'string', 'regex:'.self::ATTRIBUTE_PATTERN],
            'directory.timeout_s' => ['required', 'integer', 'between:1,30'],
            'directory.duration_hours' => ['nullable', 'numeric', 'min:0'],
        ], [], collect(['host', 'port', 'tls', 'ca_cert', 'base_dn', 'bind_dn', 'bind_password', 'user_filter', 'group_dn',
            'name_attributes', 'email_attribute', 'timeout_s', 'duration_hours'])
            ->mapWithKeys(fn ($f) => ["directory.$f" => __('portal.directory.fields.'.$f)])->all());

        $validator->after(function ($v) use ($request, $enabled, $hasPassword) {
            $input = (array) $request->input('directory', []);
            $filter = (string) ($input['user_filter'] ?? '');
            if ($filter !== '' && ! self::validFilter($filter)) {
                $v->errors()->add('directory.user_filter', __('portal.directory.errors.filter'));
            }
            $names = self::attributeList((string) ($input['name_attributes'] ?? ''));
            if ($names === null) {
                $v->errors()->add('directory.name_attributes', __('portal.directory.errors.attributes'));
            }
            $ca = trim((string) ($input['ca_cert'] ?? ''));
            if ($ca !== '' && ! self::validCaCert($ca)) {
                $v->errors()->add('directory.ca_cert', __('portal.directory.errors.ca_cert'));
            }
            $hours = $input['duration_hours'] ?? null;
            if (is_numeric($hours) && ((int) round($hours * 3600) < DirectorySettings::MIN_DURATION_S || (int) round($hours * 3600) > DirectorySettings::MAX_DURATION_S)) {
                $v->errors()->add('directory.duration_hours', __('portal.directory.errors.duration'));
            }
            // A service account needs its password (unless one is stored and kept).
            if ($enabled && trim((string) ($input['bind_dn'] ?? '')) !== '' && ! $hasPassword && (string) ($input['bind_password'] ?? '') === '') {
                $v->errors()->add('directory.bind_password', __('portal.directory.errors.password_required'));
            }
        });
        if ($validator->fails()) {
            return redirect()->to(route('admin.sites.edit', $site).'#directory')->withErrors($validator)
                ->withInput($request->except('directory.bind_password'));
        }
        $data = $validator->validated()['directory'];

        $hours = $data['duration_hours'] ?? null;
        $config = [
            'enabled' => $enabled,
            'host' => strtolower(trim((string) ($data['host'] ?? ''))),
            'port' => (int) $data['port'],
            'tls' => $data['tls'],
            'verify' => $request->boolean('directory.verify'),
            'ca_cert' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['ca_cert'] ?? ''))),
            'base_dn' => trim((string) ($data['base_dn'] ?? '')),
            'bind_dn' => trim((string) ($data['bind_dn'] ?? '')),
            'user_filter' => trim($data['user_filter']),
            'group_dn' => trim((string) ($data['group_dn'] ?? '')),
            'name_attributes' => self::attributeList((string) ($data['name_attributes'] ?? '')) ?: DirectorySettings::SITE_DEFAULTS['name_attributes'],
            'email_attribute' => trim((string) ($data['email_attribute'] ?? '')),
            'timeout_s' => (int) $data['timeout_s'],
            'duration_s' => $hours === null || $hours === '' ? null : (int) round($hours * 3600),
        ];
        $newPassword = (string) ($data['bind_password'] ?? '');
        $password = $request->boolean('directory.bind_password_clear') ? '' : ($newPassword !== '' ? $newPassword : null);

        $site->settings = DirectorySettings::mergeInto($site, $config, $password);
        $site->save();
        // No password, no DNs: host, transport and what changed.
        Audit::log('site.directory_updated', $site, ['site' => $site->slug, 'enabled' => $config['enabled'], 'host' => $config['host'],
            'port' => $config['port'], 'tls' => $config['tls'], 'verify' => $config['verify'], 'own_ca' => $config['ca_cert'] !== '',
            'group_required' => $config['group_dn'] !== '', 'credential_changed' => $password !== null]);

        $response = redirect()->to(route('admin.sites.edit', $site).'#directory')->with('status', __('portal.saved'));
        if ($config['tls'] === DirectorySettings::TLS_NONE || ! $config['verify']) {
            $response->with('warning', __($config['tls'] === DirectorySettings::TLS_NONE ? 'portal.directory.tls_none_warning' : 'portal.directory.verify_off_warning'));
        }

        return $response;
    }

    /** Check button: service bind and the user search with a made-up name; the result is flashed. */
    public function check(Site $site, DirectoryLogin $directory): RedirectResponse
    {
        $status = $directory->check(DirectorySettings::config($site));
        Log::info('Site directory checked', ['site' => $site->slug, 'status' => $status]);
        Audit::log('site.directory_checked', $site, ['site' => $site->slug, 'status' => $status]);

        return redirect()->to(route('admin.sites.edit', $site).'#directory')->with('directory_check', [
            'status' => $status,
            'message' => __('portal.directory.check.'.(in_array($status, self::CHECK_MESSAGES, true) ? $status : 'failed')),
        ]);
    }

    /** Check results with an own message (others: "failed"). */
    public const CHECK_MESSAGES = ['ok', 'incomplete', 'unreachable', 'tls', 'service_bind_failed', 'search_failed', 'ambiguous'];

    /** Filter in parentheses, balanced (escapes \xx do not count), with {username}. */
    public static function validFilter(string $filter): bool
    {
        $filter = trim($filter);
        if (! str_contains($filter, '{username}') || ! str_starts_with($filter, '(') || ! str_ends_with($filter, ')')
            || preg_match('/[\x00-\x1F]/', $filter) === 1) {
            return false;
        }
        $depth = 0;
        foreach (str_split($filter) as $i => $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($depth < 0 || ($depth === 0 && $i < strlen($filter) - 1)) {
                return false;
            }
        }

        return $depth === 0;
    }

    /** @return list<string>|null comma separated attribute names (max. 3), null when invalid; [] when empty */
    public static function attributeList(string $value): ?array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($n) => $n !== ''));
        foreach ($names as $name) {
            if (preg_match(self::ATTRIBUTE_PATTERN, $name) !== 1) {
                return null;
            }
        }

        return count($names) <= 3 ? $names : null;
    }

    /** One or more PEM certificates, each readable. */
    public static function validCaCert(string $pem): bool
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $blocks) < 1) {
            return false;
        }
        foreach ($blocks[0] as $block) {
            if (@openssl_x509_read($block) === false) {
                return false;
            }
        }

        return true;
    }
}
