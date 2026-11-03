<?php

namespace App\Auth\Directory;

/**
 * Minimal LDAP client used by the directory account login (CONCEPT.md §7.2 E). The real implementation
 * is LdapDirectoryClient (ext/ldap); tests bind a fake. One instance serves one connection at a time.
 */
interface DirectoryClient
{
    /**
     * Open the connection (TLS per config; StartTLS is negotiated here).
     *
     * @throws DirectoryException UNREACHABLE or TLS
     */
    public function connect(DirectoryConfig $config): void;

    /**
     * Simple bind. An empty DN means an anonymous bind (only used for the service account step).
     *
     * @throws DirectoryException INVALID_CREDENTIALS, ACCOUNT_RESTRICTED, UNREACHABLE or TLS
     */
    public function bind(string $dn, #[\SensitiveParameter] string $password): void;

    /**
     * Subtree search; at most a few entries are returned (size limit).
     *
     * @param  list<string>  $attributes
     * @return list<array{dn: string, attributes: array<string, list<string>>}> attribute names in lower case
     *
     * @throws DirectoryException SEARCH_FAILED, UNREACHABLE or TLS
     */
    public function search(string $baseDn, string $filter, array $attributes): array;

    /** Close the connection and remove temporary files; safe to call more than once. */
    public function close(): void;
}
