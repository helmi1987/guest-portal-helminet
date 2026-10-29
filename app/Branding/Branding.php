<?php

namespace App\Branding;

use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Effective branding of one page (docs/branding.md): logo, favicon, theme stylesheet (colour
 * variables + custom CSS) and the stable body classes.
 */
final class Branding
{
    /** Built-in defaults of the CSS variables (resources/css/app.css), used for the preview. */
    public const DEFAULTS = ['accent' => '#0f172a', 'background' => '#f1f5f9', 'text' => '#0f172a'];

    /**
     * @param  array{logo: ?array, favicon: ?array, accent: ?string, background: ?string, text: ?string, css: string}  $values
     * @param  bool  $siteTheme  the site overrides colours or CSS (own stylesheet), else the global one is used
     */
    public function __construct(public readonly array $values, public readonly ?Site $site = null, private readonly bool $siteTheme = false) {}

    /** URL of the uploaded logo, null when none is set or the file is missing on this instance (default logo). */
    public function logoUrl(): ?string
    {
        return $this->assetUrl($this->values['logo']);
    }

    public function faviconUrl(): ?string
    {
        return $this->assetUrl($this->values['favicon']);
    }

    public function faviconType(): ?string
    {
        return $this->faviconUrl() ? BrandingAssets::mime($this->values['favicon']['ext']) : null;
    }

    /** Stylesheet with the colour variables and the custom CSS; empty when nothing is configured. */
    public function css(): string
    {
        $vars = [];
        if ($accent = $this->values['accent']) {
            $vars[] = "--gp-accent: $accent;";
            $vars[] = '--gp-accent-contrast: '.self::contrast($accent).';';
        }
        if ($background = $this->values['background']) {
            $vars[] = "--gp-bg: $background;";
        }
        if ($text = $this->values['text']) {
            $vars[] = "--gp-text: $text;";
        }
        $css = $vars === [] ? '' : ":root {\n    ".implode("\n    ", $vars)."\n}\n";
        $custom = trim($this->values['css']);

        return $custom === '' ? $css : $css."\n/* Custom CSS */\n".$custom."\n";
    }

    /** Relative URL of the theme stylesheet (content hash as cache key), null when there is none. */
    public function themeUrl(): ?string
    {
        $css = $this->css();
        if ($css === '') {
            return null;
        }

        return route('branding.theme', ['scope' => $this->scope(), 'v' => substr(hash('sha256', $css), 0, 16)], false);
    }

    /** "global" or the site id when the site overrides colours/CSS. */
    public function scope(): string
    {
        return $this->siteTheme && $this->site ? (string) $this->site->getKey() : 'global';
    }

    /** Stable body classes: gp-page gp-guest|gp-admin [gp-site-<slug>] [gp-step-<step> [gp-step-<step>-<variant>]]. */
    public function bodyClasses(string $area, ?string $step = null, ?string $variant = null): string
    {
        $classes = ['gp-page', 'gp-'.$area];
        if ($this->site) {
            $classes[] = 'gp-site-'.self::classToken((string) $this->site->slug);
        }
        if ($step) {
            $classes[] = 'gp-step-'.self::classToken($step);
            if ($variant) {
                $classes[] = 'gp-step-'.self::classToken($step).'-'.self::classToken($variant);
            }
        }

        return implode(' ', $classes);
    }

    /** Black or white, whichever has the higher WCAG contrast ratio to the given colour. */
    public static function contrast(string $hex): string
    {
        $channels = array_map(function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        // Contrast with white (1.05 / (L + 0.05)) vs. black ((L + 0.05) / 0.05).
        return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#ffffff' : '#000000';
    }

    private function assetUrl(?array $asset): ?string
    {
        if (! app(BrandingAssets::class)->exists($asset)) {
            return null;
        }

        return route('branding.file', ['file' => BrandingAssets::fileName($asset)], false);
    }

    private static function classToken(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9_-]+/', '-', Str::lower($value)), '-') ?: 'x';
    }
}
