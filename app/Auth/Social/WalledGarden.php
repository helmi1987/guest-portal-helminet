<?php

namespace App\Auth\Social;

/**
 * Hosts a guest must reach before login to sign in with a social login provider (CONCEPT.md §7.2 D):
 * the MikroTik router script adds them as /ip hotspot walled-garden ip entries (dst-host, tcp/443,
 * action=accept, decision 2026-11-16) for the providers that are switched on and configured. RouterOS
 * resolves each name itself and lets the traffic to its addresses bypass the hotspot's HTTPS handler.
 * Verified on the CHR (RouterOS 7.24.5): such an entry accepts only plain host names; wildcards
 * ("*.example.com") and regular expressions (":^...") are marked invalid and allow nothing, as is a name
 * that does not resolve. The lists therefore hold concrete host names only.
 *
 * The providers' sign-in pages load scripts, styles and images from further hosts, so the lists include
 * their CDNs. Sources (2026-11-16): the hosts a working Google sign-in in a captive browser queried on a
 * router, the hosts referenced by the providers' sign-in pages, Microsoft's published Microsoft 365
 * endpoint list. They follow the providers' current pages and may need an update when a provider changes
 * them (docs/guest-network-setup.md).
 */
final class WalledGarden
{
    /** Google's country sign-in host (accounts.google.<tld>, e.g. the redirect to accounts.google.ch/accounts/SetSID). */
    public const GOOGLE_COUNTRY_HOST = 'accounts.google.';

    /** Allowed country domain values (Google setting "country_domains"): "ch", "de", "co.uk", "com.au". */
    public const COUNTRY_DOMAIN = '/^[a-z]{2,3}(\.[a-z]{2,3})?$/D';

    /** Default country domains of the Google sign-in (Switzerland and its neighbours). */
    public const DEFAULT_COUNTRY_DOMAINS = ['ch', 'de', 'at', 'it', 'fr', 'li'];

    public const HOSTS = [
        // Sign-in page and its static files (www/ssl/fonts.gstatic.com, fonts.googleapis.com), the session cookie
        // hosts the sign-in redirects through (accounts.youtube.com, accounts.google.<tld>: GOOGLE_COUNTRY_HOST),
        // the profile picture (lh3.googleusercontent.com), play.google.com (sign-in page logging) and the token
        // host. No wildcard host: "*.gstatic.com" also opened connectivitycheck.gstatic.com, Android's captive
        // portal probe (Android then shows no portal); www.google.com (probe www.google.com/generate_204) stays
        // out for the same reason.
        SocialSettings::GOOGLE => [
            'accounts.google.com', 'accounts.youtube.com', 'www.gstatic.com', 'ssl.gstatic.com', 'fonts.gstatic.com',
            'fonts.googleapis.com', 'lh3.googleusercontent.com', 'play.google.com', 'oauth2.googleapis.com',
        ],
        SocialSettings::APPLE => ['appleid.apple.com', 'appleid.cdn-apple.com', 'idmsa.apple.com', 'gsa.apple.com'],
        // Desktop and mobile sign-in pages (captive browsers get m.facebook.com) and their static CDN hosts
        SocialSettings::FACEBOOK => ['www.facebook.com', 'm.facebook.com', 'static.xx.fbcdn.net', 'z-m-static.xx.fbcdn.net'],
        // Sign-in page, its assets and the avatar host
        SocialSettings::GITHUB => ['github.com', 'github.githubassets.com', 'avatars.githubusercontent.com'],
        // Sign-in pages for work/school accounts (login.microsoftonline.com, login.microsoft.com) and personal
        // accounts (login.live.com, account.live.com, auth.gfx.ms), their CDNs (aadcdn, logincdn and acctcdn on
        // msauth.net and msftauth.net) and the tenant branding images (aadcdn.msauthimages.net,
        // aadcdn.msftauthimages.net). Taken from Microsoft's published Microsoft 365 endpoint list
        // (learn.microsoft.com/microsoft-365/enterprise/urls-and-ip-address-ranges, endpoint sets 56, 59, 97 and
        // 116; its wildcards replaced by the CDN hosts the sign-in pages reference), limited to what the browser
        // sign-in needs.
        SocialSettings::MICROSOFT => [
            'login.microsoftonline.com', 'login.microsoft.com', 'login.live.com', 'account.live.com', 'auth.gfx.ms',
            'aadcdn.msauth.net', 'aadcdn.msftauth.net', 'logincdn.msauth.net', 'logincdn.msftauth.net',
            'acctcdn.msauth.net', 'acctcdn.msftauth.net', 'aadcdn.msauthimages.net', 'aadcdn.msftauthimages.net',
        ],
    ];

    /**
     * Hosts of the providers offered to guests: the social login switch is on and the provider is
     * configured. Federation limited mode is not considered (a temporary state must not change the
     * router configuration).
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        if (! LoginMethods::enabled(LoginMethods::SOCIAL)) {
            return [];
        }
        $hosts = [];
        // Fixed provider order, not the display order: reordering must not change the router configuration.
        foreach (array_filter(SocialSettings::PROVIDERS, SocialSettings::isReady(...)) as $provider) {
            array_push($hosts, ...self::providerHosts($provider));
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Hosts of one provider; Google with one accounts.google.<tld> host per configured country domain.
     *
     * @return list<string>
     */
    public static function providerHosts(string $provider): array
    {
        $hosts = self::HOSTS[$provider] ?? [];
        if ($provider === SocialSettings::GOOGLE) {
            array_splice($hosts, 1, 0, array_map(fn ($tld) => self::GOOGLE_COUNTRY_HOST.$tld,
                self::countryDomains(SocialSettings::provider(SocialSettings::GOOGLE)['country_domains'] ?? [])));
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Valid country domains of a stored or entered value (list or text separated by commas or spaces), lower
     * case, without a leading dot, unique, in the given order. Invalid values are dropped: they end up in the
     * router script.
     *
     * @return list<string>
     */
    public static function countryDomains(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);
        $out = [];
        foreach ((array) $parts as $part) {
            $tld = ltrim(strtolower(trim((string) $part)), '.');
            if ($tld !== '' && preg_match(self::COUNTRY_DOMAIN, $tld) && ! in_array($tld, $out, true)) {
                $out[] = $tld;
            }
        }

        return $out;
    }

    /**
     * Object part of the comment key of a host entry ("guest-portal:walled:social-<object>"): the host in
     * lower case, "*" written as "any" (wildcard hosts of earlier versions) and "." as "-" (comment keys allow
     * only a-z, 0-9 and "-"), e.g. "social-accounts-google-ch". Unique within the host lists (tests check it).
     */
    public static function object(string $host): string
    {
        return self::OBJECT_PREFIX.str_replace(['*', '.'], ['any', '-'], strtolower($host));
    }

    /** Prefix of the object part of every social login entry (the scripts remove the own entries by it). */
    public const OBJECT_PREFIX = 'social-';
}
