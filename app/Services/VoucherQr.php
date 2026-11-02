<?php

namespace App\Services;

use App\Models\Grant;
use App\Models\Site;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;

/**
 * Voucher QR (CONCEPT.md §8): a Wi-Fi join QR for the guest SSID of the code's sites. The code is
 * printed as text; the QR carries no portal URL (the hotspot redirect drops it, link-orig is unused).
 */
class VoucherQr
{
    /** @var Collection<string, ?array{ssid: string, password: ?string, unavailable: bool}>|null site id => guest Wi-Fi of the setup assistant */
    private ?Collection $networks = null;

    /** @var array<string, string> payload@size => SVG */
    private array $svgs = [];

    /**
     * Guest Wi-Fi of the grant's sites (all sites when unrestricted): SSID and password (null: open);
     * null when none or ambiguous (sites with different SSIDs or passwords) or when the
     * password is not available on this instance (WPA site, password kept local elsewhere): a QR without
     * the password would be useless, so none is rendered.
     *
     * @return array{ssid: string, password: ?string}|null
     */
    public function networkFor(Grant $grant): ?array
    {
        $this->networks ??= Site::query()->get()->mapWithKeys(fn (Site $site) => [$site->id => self::networkForSite($site)]);

        $ids = $grant->siteIds();
        $candidates = ($ids === [] ? $this->networks : $this->networks->only($ids))->filter()
            ->unique(fn (array $n) => json_encode([$n['ssid'], $n['password'], $n['unavailable']]))->values();

        $network = $candidates->count() === 1 ? $candidates->first() : null;

        return $network === null || $network['unavailable'] ? null : ['ssid' => $network['ssid'], 'password' => $network['password']];
    }

    /** SSID of the grant's sites (all sites when unrestricted); null when none or ambiguous. */
    public function ssidFor(Grant $grant): ?string
    {
        return $this->networkFor($grant)['ssid'] ?? null;
    }

    /** Guest Wi-Fi password of the grant's sites; null when open, none or ambiguous. */
    public function passwordFor(Grant $grant): ?string
    {
        return $this->networkFor($grant)['password'] ?? null;
    }

    /**
     * WIFI: payload (ZXing format; \ ; , : " escaped): open network, or WPA with a password. Values are never
     * quoted: phones would take the quotes as part of the SSID or password. $hidden appends `H:true`
     * (hidden network).
     */
    public static function wifiPayload(string $ssid, ?string $password = null, bool $hidden = false): string
    {
        $value = function (string $v): string {
            return preg_replace('/([\\\\;,:"])/', '\\\\$1', $v);
        };
        $h = $hidden ? 'H:true;' : '';

        return $password === null || $password === ''
            ? 'WIFI:S:'.$value($ssid).';T:nopass;'.$h.';'
            : 'WIFI:T:WPA;S:'.$value($ssid).';P:'.$value($password).';'.$h.';';
    }

    /**
     * Guest Wi-Fi of one site (setup assistant), null when no SSID is set.
     *
     * @return array{ssid: string, password: ?string, unavailable: bool}|null
     */
    public static function networkForSite(Site $site): ?array
    {
        $ssid = self::ssidForSite($site);

        return $ssid === null ? null : ['ssid' => $ssid, 'password' => $site->wifiPassword(), 'unavailable' => $site->wifiPasswordUnavailable()];
    }

    /** Guest SSID of one site (setup assistant), null when not set. */
    public static function ssidForSite(Site $site): ?string
    {
        return trim((string) $site->setting('setup.ssid')) ?: null;
    }

    /** Inline SVG markup of the Wi-Fi QR (cached per network for print sheets); password null: open. */
    public function wifiSvg(string $ssid, int $size = 160, ?string $password = null): string
    {
        return $this->svg(self::wifiPayload($ssid, $password), $size);
    }

    /** Inline SVG markup of a QR code for any payload (cached per payload and size). */
    public function svg(string $payload, int $size = 160): string
    {
        return $this->svgs[$payload.'@'.$size] ??= preg_replace('/^<\?xml[^>]*>\s*/', '',
            (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($payload));
    }
}
