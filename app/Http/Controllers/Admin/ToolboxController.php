<?php

namespace App\Http\Controllers\Admin;

use App\Branding\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Services\VoucherQr;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin toolbox (CONCEPT.md §9, admin only): standalone helpers, independent of sites and settings.
 * Wi-Fi QR code: prints a join QR for any SSID/password. Nothing is stored, logged or audited; the
 * password field is never flashed as old input (`wifi_password` in dontFlash, bootstrap/app.php).
 */
class ToolboxController extends Controller
{
    public function index(): View
    {
        return view('admin.toolbox.index');
    }

    public function wifiQrForm(): View
    {
        return view('admin.toolbox.wifi-qr');
    }

    public function wifiQrPrint(Request $request, VoucherQr $qr): Response
    {
        $data = $request->validate([
            'ssid' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (strlen((string) $value) > 32) {
                    $fail(__('portal.toolbox.wifi_qr.ssid_too_long'));
                }
            }],
            'encryption' => ['required', Rule::in(['WPA', 'nopass'])],
            'wifi_password' => ['nullable', 'string', 'max:63', 'required_if:encryption,WPA', Rule::when($request->input('encryption') === 'WPA', ['min:8'])],
            'hidden' => ['nullable', 'boolean'],
            'show_password' => ['nullable', 'boolean'],
            'format' => ['required', Rule::in(['a4', 'a6'])],
        ], [], [
            'ssid' => __('portal.toolbox.wifi_qr.ssid'),
            'wifi_password' => __('portal.toolbox.wifi_qr.password'),
            'encryption' => __('portal.toolbox.wifi_qr.encryption'),
            'format' => __('portal.toolbox.wifi_qr.format'),
        ]);

        $ssid = (string) $data['ssid'];
        $password = $data['encryption'] === 'WPA' ? (string) $data['wifi_password'] : null;
        $hidden = (bool) ($data['hidden'] ?? false);

        return response()->view('admin.toolbox.wifi-qr-print', [
            'ssid' => $ssid,
            'password' => $password,
            'hidden' => $hidden,
            'showPassword' => $password !== null && (bool) ($data['show_password'] ?? false),
            'format' => $data['format'],
            'branding' => BrandingSettings::resolve(null),
            'qr' => $qr->svg(VoucherQr::wifiPayload($ssid, $password, $hidden), 240),
        ])->header('Cache-Control', 'no-store, private');
    }
}
