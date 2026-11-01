<?php

namespace App\Http\Controllers\Admin;

use App\Branding\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Services\VoucherQr;
use Illuminate\Http\JsonResponse;
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
        $data = $this->validateWifiQr($request);
        [$ssid, $password, $hidden] = $this->wifiQrParts($data);

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

    /**
     * Live preview (JSON): server-generated SVG plus SSID and, only when "print password as text" is
     * checked, the password. Invalid input yields 422 JSON. Nothing is logged, audited or stored.
     */
    public function wifiQrPreview(Request $request, VoucherQr $qr): JsonResponse
    {
        $data = $this->validateWifiQr($request);
        [$ssid, $password, $hidden] = $this->wifiQrParts($data);

        return response()->json([
            'svg' => $qr->svg(VoucherQr::wifiPayload($ssid, $password, $hidden), 240),
            'ssid' => $ssid,
            'password' => $password !== null && (bool) ($data['show_password'] ?? false) ? $password : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function validateWifiQr(Request $request): array
    {
        return $request->validate([
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
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: ?string, 2: bool} SSID, password (null for an open network), hidden
     */
    private function wifiQrParts(array $data): array
    {
        return [
            (string) $data['ssid'],
            $data['encryption'] === 'WPA' ? (string) $data['wifi_password'] : null,
            (bool) ($data['hidden'] ?? false),
        ];
    }
}
