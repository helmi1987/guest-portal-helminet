<?php

namespace App\Auth\Directory;

/**
 * Connection and search settings of one site's directory (CONCEPT.md §7.2 E), built by
 * DirectorySettings::config(). Carries the decrypted service account password: never logged, never
 * dumped (__debugInfo leaves it out).
 */
final class DirectoryConfig
{
    /**
     * @param  list<string>  $nameAttributes  attributes tried in order for the full name
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $tls,
        public readonly bool $verify,
        public readonly string $caCert,
        public readonly string $baseDn,
        public readonly string $bindDn,
        #[\SensitiveParameter] public readonly string $bindPassword,
        public readonly string $userFilter,
        public readonly string $groupDn,
        public readonly array $nameAttributes,
        public readonly string $emailAttribute,
        public readonly int $timeoutS,
    ) {}

    /** ldaps://host:port or ldap://host:port (StartTLS and "none" use ldap://); IPv6 in brackets. */
    public function uri(): string
    {
        $scheme = $this->tls === DirectorySettings::TLS_LDAPS ? 'ldaps' : 'ldap';
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return $scheme.'://'.$host.':'.$this->port;
    }

    /** The user filter with the escaped user name in place of {username}. */
    public function filterFor(string $username): string
    {
        return str_replace('{username}', self::escapeFilterValue($username), $this->userFilter);
    }

    /** RFC 4515 value escaping (ldap_escape with LDAP_ESCAPE_FILTER; also escapes NUL, *, (, ), \). */
    public static function escapeFilterValue(string $value): string
    {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $vars = get_object_vars($this);
        unset($vars['bindPassword']);

        return $vars;
    }
}
