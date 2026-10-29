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
    /** @var Collection<string, ?string>|null site id => SSID from the setup assistant */
    private ?Collection $ssids = null;

    /** @var array<string, string> payload@size => SVG */
    private array $svgs = [];

    /** SSID of the grant's sites (all sites when unrestricted); null when none or ambiguous. */
    public function ssidFor(Grant $grant): ?string
    {
        $this->ssids ??= Site::query()->get()
            ->mapWithKeys(fn (Site $site) => [$site->id => trim((string) $site->setting('setup.ssid')) ?: null]);

        $ids = $grant->siteIds();
        $candidates = ($ids === [] ? $this->ssids : $this->ssids->only($ids))->filter()->unique()->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * WIFI: payload for an open network (ZXing format; \ ; , : " escaped). An SSID of hex digits only
     * is quoted, otherwise readers may take it for a hex-encoded name.
     */
    public static function wifiPayload(string $ssid): string
    {
        $escaped = preg_replace('/([\\\\;,:"])/', '\\\\$1', $ssid);

        return 'WIFI:S:'.(ctype_xdigit($ssid) ? '"'.$escaped.'"' : $escaped).';T:nopass;;';
    }

    /** Guest SSID of one site (setup assistant), null when not set. */
    public static function ssidForSite(Site $site): ?string
    {
        return trim((string) $site->setting('setup.ssid')) ?: null;
    }

    /**
     * Sign-in URL of a site notice (CONCEPT.md §8, site code): the router's hotspot login page with the
     * portal URL incl. ?code= as dst, so the router redirect (MAC, login URL) reaches the portal and the
     * code arrives in link-orig (prefill only). Without a hotspot host: the portal URL itself.
     */
    public static function signInUrl(Site $site, string $code): string
    {
        $portal = rtrim((string) ($site->setting('portal_url') ?: config('app.url')), '/')
            .'/portal?'.http_build_query(['site' => $site->slug, 'code' => $code]);
        $host = ((array) $site->setting('hotspot_hosts', []))[0] ?? null;

        return $host ? 'https://'.$host.'/login?'.http_build_query(['dst' => $portal]) : $portal;
    }

    /** Inline SVG markup of the Wi-Fi QR (cached per SSID for print sheets). */
    public function wifiSvg(string $ssid, int $size = 160): string
    {
        return $this->svg(self::wifiPayload($ssid), $size);
    }

    /** Inline SVG markup of a QR code for any payload (cached per payload and size). */
    public function svg(string $payload, int $size = 160): string
    {
        return $this->svgs[$payload.'@'.$size] ??= preg_replace('/^<\?xml[^>]*>\s*/', '',
            (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($payload));
    }
}
