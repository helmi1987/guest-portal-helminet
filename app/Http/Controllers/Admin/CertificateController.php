<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Tls\CertificateException;
use App\Tls\CertificateInfo;
use App\Tls\CertificatePuller;
use App\Tls\CertificateSources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Certificates (admin only, CONCEPT.md §6): current portal and router certificate, source settings
 * (none / CertWarden / URL) and "pull now".
 */
class CertificateController extends Controller
{
    public function index(): View
    {
        $targets = [];
        foreach (CertificateSources::TARGETS as $target) {
            $targets[$target] = [
                'paths' => CertificateSources::paths($target),
                'info' => CertificateInfo::fromFile(CertificateSources::paths($target)['cert']),
                'source' => CertificateSources::get($target),
                'status' => CertificateSources::status($target),
                'others' => CertificateSources::others($target),
            ];
        }

        return view('admin.certificates', [
            'targets' => $targets,
            'portalHost' => CertificateSources::portalHost(),
        ]);
    }

    /** Take over the certificate source of another instance (replacement instance). */
    public function copy(Request $request, string $target): RedirectResponse
    {
        abort_unless(in_array($target, CertificateSources::TARGETS, true), 404);
        $instance = (string) $request->validate(['instance' => ['required', 'string', 'size:26']])['instance'];
        if (! CertificateSources::copyFrom($instance, $target)) {
            return back()->withErrors(['instance' => __('portal.certificates.copy_missing')]);
        }
        Audit::log('certificate.source_copied', null, ['target' => $target, 'from' => $instance]);

        return back()->with('status', __('portal.certificates.copied'));
    }

    public function update(Request $request, string $target): RedirectResponse
    {
        abort_unless(in_array($target, CertificateSources::TARGETS, true), 404);
        $current = CertificateSources::get($target);
        // The form shows the URL with a masked query; unchanged means: keep the stored URL.
        if ($current['url'] !== null && $request->input('url') === CertificateSources::maskUrl($current['url'])) {
            $request->merge(['url' => $current['url']]);
        }
        $certwarden = $request->input('type') === 'certwarden';
        // A stored key counts for CertWarden only if the source already was CertWarden (no bearer token reuse).
        $storedCertwarden = $current['has_api_key'] && $current['type'] === 'certwarden';
        $data = $request->validate([
            'type' => ['required', Rule::in(CertificateSources::TYPES)],
            'url' => ['nullable', 'required_unless:type,none', 'url:https', 'max:500'],
            'name' => ['nullable', 'required_if:type,certwarden', 'regex:/^[A-Za-z0-9._-]+$/', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:500'],
            // CertWarden: two keys, combined as "<certificate API key>.<private key API key>"
            // (a combined value in api_key is still accepted, e.g. from scripts using the old form field)
            'api_key_cert' => ['nullable', ...($certwarden && ! $storedCertwarden ? ['required_without:api_key'] : []), 'string', 'max:250', 'regex:/^[^\s.]*$/'],
            'api_key_key' => ['nullable', ...($certwarden && ! $storedCertwarden ? ['required_without:api_key'] : []), 'string', 'max:250', 'regex:/^[^\s.]*$/'],
            'remove_api_key' => ['boolean'],
            'ca_pem' => ['nullable', 'string', 'max:20000'],
        ], [
            'api_key_cert.required_without' => __('validation.required'),
            'api_key_key.required_without' => __('validation.required'),
        ]);
        if (isset($data['url']) && (parse_url($data['url'], PHP_URL_USER) !== null || parse_url($data['url'], PHP_URL_PASS) !== null)) {
            throw ValidationException::withMessages(['url' => __('portal.certificates.url_userinfo')]);
        }
        $ca = trim((string) ($data['ca_pem'] ?? ''));
        if ($ca !== '' && (! str_contains($ca, '-----BEGIN CERTIFICATE-----') || @openssl_x509_read($ca) === false)) {
            throw ValidationException::withMessages(['ca_pem' => __('portal.certificates.ca_invalid')]);
        }
        if ($data['type'] === 'certwarden' && isset($data['url'])) {
            $data['url'] = CertificateSources::normalizeCertwardenUrl($data['url']);
        }
        $apiKey = $certwarden ? $this->certwardenKey($target, $current, $data) : trim((string) ($data['api_key'] ?? ''));

        CertificateSources::put($target, $data['type'], $data['url'] ?? null, $data['name'] ?? null, $ca ?: null,
            $apiKey !== '' ? $apiKey : ($request->boolean('remove_api_key') ? '' : null));
        Audit::log('certificate.source', null, ['target' => $target, 'type' => $data['type'], 'url' => CertificateSources::maskUrl($data['url'] ?? null),
            'name' => $data['name'] ?? null, 'custom_ca' => $ca !== '']);

        return back()->with('status', __('portal.saved'));
    }

    /**
     * New combined CertWarden key from the two fields; an empty field keeps the stored part (also of
     * a combined value saved before the two fields existed). '' = keep the stored key unchanged.
     *
     * @throws ValidationException when only one key is entered and no stored key exists
     */
    private function certwardenKey(string $target, array $current, array $data): string
    {
        $cert = trim((string) ($data['api_key_cert'] ?? ''));
        $key = trim((string) ($data['api_key_key'] ?? ''));
        if ($cert === '' && $key === '') {
            return trim((string) ($data['api_key'] ?? ''));
        }
        [$storedCert, $storedKey] = CertificateSources::splitCertwardenKey($current['has_api_key'] && $current['type'] === 'certwarden'
            ? CertificateSources::apiKey($target) : null);
        $cert = $cert !== '' ? $cert : $storedCert;
        $key = $key !== '' ? $key : $storedKey;
        if ($cert === '' || $key === '') {
            throw ValidationException::withMessages([($cert === '' ? 'api_key_cert' : 'api_key_key') => __('validation.required',
                ['attribute' => __('portal.certificates.'.($cert === '' ? 'api_key_cert' : 'api_key_key'))])]);
        }

        return CertificateSources::combineCertwardenKeys($cert, $key);
    }

    public function pull(string $target, CertificatePuller $puller): RedirectResponse
    {
        abort_unless(in_array($target, CertificateSources::TARGETS, true), 404);
        try {
            $result = $puller->pull($target);
        } catch (CertificateException $e) {
            Audit::log('certificate.pulled', null, ['target' => $target, 'result' => CertificatePuller::FAILED]);

            $hint = CertificateSources::errorHint($e->getMessage());

            return back()->withErrors(['pull' => __('portal.certificates.pull_failed', ['error' => $e->getMessage()]).($hint ? ' '.$hint : '')]);
        }
        Audit::log('certificate.pulled', null, ['target' => $target, 'result' => $result]);

        return back()->with('status', __('portal.certificates.pull_'.$result));
    }
}
