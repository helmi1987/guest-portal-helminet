<?php

namespace App\Auth\Directory;

use Closure;

/**
 * Directory side of the account login (CONCEPT.md §7.2 E): service bind (or anonymous), search of the
 * user with the site's filter ({username} escaped per RFC 4515), bind as the found DN with the guest's
 * password (the password is only ever sent to the directory, never stored or compared here), group
 * check after the successful bind (so a guess never learns the membership) and the names. The check
 * button runs the same service bind and search with a made-up user name.
 */
class DirectoryLogin
{
    /** Made-up user name of the check button's search. */
    public const CHECK_USERNAME = 'portal-directory-check';

    public const CHECK_OK = 'ok';

    public const CHECK_INCOMPLETE = 'incomplete';

    /** @param  Closure(): DirectoryClient  $clients  a fresh client per login */
    public function __construct(private Closure $clients) {}

    /**
     * User name as accepted: trimmed, 1-128 characters, no control characters. Null when unusable.
     */
    public static function normaliseUsername(mixed $username): ?string
    {
        $username = is_string($username) ? trim($username) : '';

        return $username !== '' && mb_strlen($username) <= 128 && preg_match('/[\x00-\x1F\x7F]/', $username) !== 1 ? $username : null;
    }

    /**
     * Verify user name and password against the directory.
     *
     * @throws DirectoryException
     */
    public function authenticate(DirectoryConfig $config, string $username, #[\SensitiveParameter] string $password): DirectoryUser
    {
        // An empty password would be an unauthenticated bind, which many servers accept (RFC 4513 5.1.2);
        // a NUL byte would cut the password short in the C library.
        if (self::normaliseUsername($username) === null || $password === '' || str_contains($password, "\0")) {
            throw new DirectoryException(DirectoryException::INVALID_CREDENTIALS, 'Empty or invalid user name or password');
        }

        return $this->session($config, function (DirectoryClient $client) use ($config, $username, $password) {
            $entry = $this->findUser($client, $config, $username);
            $client->bind($entry['dn'], $password);
            if ($config->groupDn !== '' && ! self::isMember($entry['attributes'], $config->groupDn)) {
                throw new DirectoryException(DirectoryException::NOT_MEMBER, 'User is not a member of the required group');
            }

            return DirectoryUser::fromAttributes($username, $entry['attributes'], $config->nameAttributes, $config->emailAttribute);
        });
    }

    /**
     * Check button: connect, service bind and the user search with a made-up name (no match expected).
     *
     * @return string CHECK_OK or a DirectoryException reason
     */
    public function check(DirectoryConfig $config): string
    {
        if ($config->host === '' || $config->baseDn === '' || ! str_contains($config->userFilter, '{username}')) {
            return self::CHECK_INCOMPLETE;
        }
        try {
            $this->session($config, function (DirectoryClient $client) use ($config) {
                $this->serviceBind($client, $config);
                $client->search($config->baseDn, $config->filterFor(self::CHECK_USERNAME), ['dn']);
            });
        } catch (DirectoryException $e) {
            // Two entries for a made-up name: the filter works but does not narrow down the user name.
            return $e->reason;
        }

        return self::CHECK_OK;
    }

    /** Attributes read from the user entry. */
    public static function attributes(DirectoryConfig $config): array
    {
        return array_values(array_unique(array_filter([...$config->nameAttributes, 'givenName', 'sn', $config->emailAttribute,
            $config->groupDn !== '' ? 'memberOf' : ''])));
    }

    /** memberOf contains the group DN (compared case-insensitively, spaces after separators ignored). */
    public static function isMember(array $attributes, string $groupDn): bool
    {
        $wanted = self::normaliseDn($groupDn);
        foreach ($attributes['memberof'] ?? [] as $dn) {
            if (self::normaliseDn($dn) === $wanted) {
                return true;
            }
        }

        return false;
    }

    public static function normaliseDn(string $dn): string
    {
        return mb_strtolower((string) preg_replace('/\s*([,=+])\s*/', '$1', trim($dn)));
    }

    /**
     * @return array{dn: string, attributes: array<string, list<string>>}
     *
     * @throws DirectoryException
     */
    private function findUser(DirectoryClient $client, DirectoryConfig $config, string $username): array
    {
        $this->serviceBind($client, $config);
        $entries = $client->search($config->baseDn, $config->filterFor($username), self::attributes($config));
        if ($entries === []) {
            throw new DirectoryException(DirectoryException::NOT_FOUND, 'No entry matches the user filter');
        }
        if (count($entries) > 1) {
            throw new DirectoryException(DirectoryException::AMBIGUOUS, 'More than one entry matches the user filter');
        }
        if ($entries[0]['dn'] === '') {
            throw new DirectoryException(DirectoryException::NOT_FOUND, 'Entry without DN');
        }

        return $entries[0];
    }

    private function serviceBind(DirectoryClient $client, DirectoryConfig $config): void
    {
        // A bind DN with an empty password would be an unauthenticated bind, not the service account.
        if ($config->bindDn !== '' && $config->bindPassword === '') {
            throw new DirectoryException(DirectoryException::SERVICE_BIND_FAILED, 'Service account without a readable password');
        }
        try {
            $client->bind($config->bindDn, $config->bindPassword);
        } catch (DirectoryException $e) {
            if (in_array($e->reason, [DirectoryException::INVALID_CREDENTIALS, DirectoryException::ACCOUNT_RESTRICTED], true)) {
                throw new DirectoryException(DirectoryException::SERVICE_BIND_FAILED, 'Service account: '.$e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(DirectoryClient): T  $work
     * @return T
     */
    private function session(DirectoryConfig $config, Closure $work): mixed
    {
        $client = ($this->clients)();
        try {
            $client->connect($config);

            return $work($client);
        } finally {
            $client->close();
        }
    }
}
