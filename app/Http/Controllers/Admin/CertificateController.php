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
        $data = $request->validate([
            'type' => ['required', Rule::in(CertificateSources::TYPES)],
            'url' => ['nullable', 'required_unless:type,none', 'url:https', 'max:500'],
            'name' => ['nullable', 'required_if:type,certwarden', 'regex:/^[A-Za-z0-9._-]+$/', 'max:200'],
            'api_key' => [$request->input('type') === 'certwarden' && ! $current['has_api_key'] ? 'required' : 'nullable',
                'string', 'max:500'],
            'remove_api_key' => ['boolean'],
            'ca_pem' => ['nullable', 'string', 'max:20000'],
        ]);
        if (isset($data['url']) && (parse_url($data['url'], PHP_URL_USER) !== null || parse_url($data['url'], PHP_URL_PASS) !== null)) {
            throw ValidationException::withMessages(['url' => __('portal.certificates.url_userinfo')]);
        }
        $ca = trim((string) ($data['ca_pem'] ?? ''));
        if ($ca !== '' && (! str_contains($ca, '-----BEGIN CERTIFICATE-----') || @openssl_x509_read($ca) === false)) {
            throw ValidationException::withMessages(['ca_pem' => __('portal.certificates.ca_invalid')]);
        }
        $apiKey = trim((string) ($data['api_key'] ?? ''));

        CertificateSources::put($target, $data['type'], $data['url'] ?? null, $data['name'] ?? null, $ca ?: null,
            $apiKey !== '' ? $apiKey : ($request->boolean('remove_api_key') ? '' : null));
        Audit::log('certificate.source', null, ['target' => $target, 'type' => $data['type'], 'url' => CertificateSources::maskUrl($data['url'] ?? null),
            'name' => $data['name'] ?? null, 'custom_ca' => $ca !== '']);

        return back()->with('status', __('portal.saved'));
    }

    public function pull(string $target, CertificatePuller $puller): RedirectResponse
    {
        abort_unless(in_array($target, CertificateSources::TARGETS, true), 404);
        try {
            $result = $puller->pull($target);
        } catch (CertificateException $e) {
            Audit::log('certificate.pulled', null, ['target' => $target, 'result' => CertificatePuller::FAILED]);

            return back()->withErrors(['pull' => __('portal.certificates.pull_failed', ['error' => $e->getMessage()])]);
        }
        Audit::log('certificate.pulled', null, ['target' => $target, 'result' => $result]);

        return back()->with('status', __('portal.certificates.pull_'.$result));
    }
}
