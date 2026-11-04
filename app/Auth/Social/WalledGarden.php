<?php

namespace App\Auth\Social;

/**
 * Hosts a guest must reach before login to sign in with a social login provider (CONCEPT.md §7.2 D):
 * the router script adds them as /ip hotspot walled-garden entries (dst-host, action=allow) for the
 * providers that are switched on and configured. The providers' sign-in pages load scripts, styles and
 * images from further hosts, so the lists include their CDNs; they follow the providers' current pages
 * and may need an update when a provider changes them (docs/guest-network-setup.md).
 */
final class WalledGarden
{
    public const HOSTS = [
        SocialSettings::GOOGLE => [
            'accounts.google.com', '*.googleusercontent.com', '*.gstatic.com', 'apis.google.com',
            'oauth2.googleapis.com', 'www.googleapis.com', 'play.google.com', 'fonts.googleapis.com',
        ],
        SocialSettings::APPLE => [
            'appleid.apple.com', 'appleid.cdn-apple.com', 'idmsa.apple.com', 'gsa.apple.com', '*.cdn-apple.com',
        ],
        SocialSettings::FACEBOOK => ['*.facebook.com', '*.fbcdn.net', '*.facebook.net', '*.fbsbx.com'],
        SocialSettings::GITHUB => ['github.com', '*.github.com', '*.githubassets.com', '*.githubusercontent.com'],
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
            array_push($hosts, ...self::HOSTS[$provider]);
        }

        return array_values(array_unique($hosts));
    }

    /** @return list<string> hosts of all providers (the router script removes the own entries of these) */
    public static function allHosts(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::HOSTS))));
    }

    /**
     * Object part of the comment key of a host entry ("guest-portal:walled:social-<object>"): the host in
     * lower case, "*" written as "any" and "." as "-" (comment keys allow only a-z, 0-9 and "-"), e.g.
     * "social-any-gstatic-com". Unique within HOSTS (tests check it).
     */
    public static function object(string $host): string
    {
        return 'social-'.str_replace(['*', '.'], ['any', '-'], strtolower($host));
    }
}
