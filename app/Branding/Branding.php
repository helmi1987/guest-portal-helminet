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
    /** Built-in defaults of the colour variables per scheme (resources/css/app.css), shown as placeholders. */
    public const DEFAULTS = [
        'dark' => ['accent' => '#e2e8f0', 'background' => '#0f172a', 'text' => '#e2e8f0'],
        'light' => ['accent' => '#0f172a', 'background' => '#f1f5f9', 'text' => '#0f172a'],
    ];

    /**
     * Variables derived from --gp-bg and --gp-text when a custom background or text colour is set, so
     * cards, inputs and borders follow the custom colours in both schemes (also used by the settings preview).
     */
    public const DERIVED = [
        '--gp-surface' => 'color-mix(in srgb, var(--gp-bg) 92%, var(--gp-text))',
        '--gp-input-bg' => 'var(--gp-bg)',
        '--gp-input-text' => 'var(--gp-text)',
        '--gp-border' => 'color-mix(in srgb, var(--gp-bg) 60%, var(--gp-text))',
        '--gp-divider' => 'color-mix(in srgb, var(--gp-text) 15%, transparent)',
    ];

    /**
     * @param  array{logo: ?array, favicon: ?array, accent: ?string, background: ?string, text: ?string, css: string, scheme?: string}  $values
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

    /** Fixed colour scheme: "dark" (default) or "light". */
    public function scheme(): string
    {
        return BrandingSettings::scheme($this->values['scheme'] ?? null) ?? BrandingSettings::SCHEMES[0];
    }

    /** Theme class on <html> and <body>; the scheme defaults of the --gp-* variables hang on the <html> one. */
    public function themeClass(): string
    {
        return 'gp-theme-'.$this->scheme();
    }

    /** Stylesheet with the colour variables and the custom CSS; empty when nothing is configured. */
    public function css(): string
    {
        $vars = [];
        if ($accent = $this->values['accent']) {
            $vars[] = "--gp-accent: $accent;";
            $vars[] = '--gp-accent-contrast: '.self::contrast($accent).';';
            // A custom accent also colours the admin top bar in the dark scheme (as in the light one).
            $vars[] = '--gp-header-bg: var(--gp-accent);';
            $vars[] = '--gp-header-text: var(--gp-accent-contrast);';
        }
        if ($background = $this->values['background']) {
            $vars[] = "--gp-bg: $background;";
        }
        if ($text = $this->values['text']) {
            $vars[] = "--gp-text: $text;";
        }
        if ($background || $text) {
            foreach (self::DERIVED as $name => $value) {
                $vars[] = "$name: $value;";
            }
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

    /** Stable body classes: gp-page gp-guest|gp-admin [gp-site-<slug>] [gp-step-<step> [gp-step-<step>-<variant>]] gp-theme-dark|light. */
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
        $classes[] = $this->themeClass();

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
