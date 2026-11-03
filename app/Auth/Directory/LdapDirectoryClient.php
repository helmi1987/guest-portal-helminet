<?php

namespace App\Auth\Directory;

use LDAP\Connection;

/**
 * DirectoryClient on ext/ldap (CONCEPT.md §7.2 E, §12): LDAPv3, no referrals, network, operation and
 * search time limits from the site setting, TLS per site (ldaps, StartTLS or none) with the certificate
 * checked (hard) unless the admin switched verification off; an optional CA certificate (PEM) of the
 * site replaces the system store for this connection (temporary file, removed on close). Diagnostic messages are logged by the caller
 * only as a reason code; passwords never leave this class.
 */
final class LdapDirectoryClient implements DirectoryClient
{
    /** OpenLDAP result codes (negative: client side). */
    private const SERVER_DOWN = -1;

    private const TIMEOUT = -5;

    private const CONNECT_ERROR = -11;

    private const SIZELIMIT_EXCEEDED = 4;

    private const INVALID_CREDENTIALS = 49;

    /** Active Directory "data" codes of a failed bind that mean disabled, locked or expired. */
    public const AD_RESTRICTED = ['530', '531', '532', '533', '701', '773', '775'];

    /** At most this many entries per search: one expected, two mean "ambiguous". */
    private const SIZE_LIMIT = 2;

    private ?Connection $link = null;

    private ?string $caDir = null;

    private ?DirectoryConfig $config = null;

    public function connect(DirectoryConfig $config): void
    {
        $this->close();
        if (! function_exists('ldap_connect')) {
            throw new DirectoryException(DirectoryException::UNREACHABLE, 'PHP extension ldap is not installed');
        }
        $this->config = $config;

        // TLS options: ext/ldap has no way to give one connection its own TLS context (OpenLDAP's
        // LDAP_OPT_X_TLS_NEWCTX is not exposed and options set on a connection are ignored), so they are set
        // globally for this PHP worker right before connecting, every time and all of them, so nothing of
        // another site's connection stays: certificate check, and the CA file and directory (the site's own
        // CA alone, otherwise the system store).
        foreach ($this->tlsOptions($config) as $option => $value) {
            @ldap_set_option(null, $option, $value);
        }

        $link = @ldap_connect($config->uri());
        if ($link === false) {
            // The host is validated when saved; a failure here is the TLS setup (e.g. an unreadable CA file).
            throw new DirectoryException($config->tls === DirectorySettings::TLS_NONE ? DirectoryException::UNREACHABLE : DirectoryException::TLS,
                'The connection could not be set up');
        }
        ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, $config->timeoutS);
        ldap_set_option($link, LDAP_OPT_TIMEOUT, $config->timeoutS);
        ldap_set_option($link, LDAP_OPT_TIMELIMIT, $config->timeoutS);
        ldap_set_option($link, LDAP_OPT_SIZELIMIT, self::SIZE_LIMIT);
        $this->link = $link;

        if ($config->tls === DirectorySettings::TLS_STARTTLS && ! @ldap_start_tls($link)) {
            throw $this->failure('StartTLS failed', starttls: true);
        }
    }

    public function bind(string $dn, #[\SensitiveParameter] string $password): void
    {
        $link = $this->link ?? throw new DirectoryException(DirectoryException::UNREACHABLE, 'Not connected');
        $ok = $dn === '' ? @ldap_bind($link) : @ldap_bind($link, $dn, $password);
        if (! $ok) {
            throw $this->failure('Bind failed');
        }
    }

    public function search(string $baseDn, string $filter, array $attributes): array
    {
        $link = $this->link ?? throw new DirectoryException(DirectoryException::UNREACHABLE, 'Not connected');
        $result = @ldap_search($link, $baseDn, $filter, array_values(array_unique($attributes)), 0, self::SIZE_LIMIT,
            $this->config?->timeoutS ?? 5, LDAP_DEREF_NEVER);
        if ($result === false) {
            if (ldap_errno($link) === self::SIZELIMIT_EXCEEDED) {
                throw new DirectoryException(DirectoryException::AMBIGUOUS, 'More than one entry matches the user filter');
            }
            throw $this->failure('Search failed', search: true);
        }
        $entries = @ldap_get_entries($link, $result);
        if ($entries === false) {
            throw $this->failure('Search failed', search: true);
        }

        $out = [];
        for ($i = 0; $i < (int) ($entries['count'] ?? 0); $i++) {
            $entry = $entries[$i];
            $values = [];
            for ($a = 0; $a < (int) ($entry['count'] ?? 0); $a++) {
                $name = strtolower((string) $entry[$a]);
                $raw = $entry[$name] ?? [];
                $list = [];
                for ($v = 0; $v < (int) ($raw['count'] ?? 0); $v++) {
                    $list[] = (string) $raw[$v];
                }
                $values[$name] = $list;
            }
            $out[] = ['dn' => (string) $entry['dn'], 'attributes' => $values];
        }

        return $out;
    }

    public function close(): void
    {
        if ($this->link !== null) {
            @ldap_unbind($this->link);
            $this->link = null;
        }
        if ($this->caDir !== null) {
            @unlink($this->caDir.'/ca.pem');
            @rmdir($this->caDir);
            $this->caDir = null;
        }
        $this->config = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Map the last error of the connection to a reason (best effort: ext/ldap reports TLS errors as -1). */
    private function failure(string $what, bool $starttls = false, bool $search = false): DirectoryException
    {
        $errno = $this->link !== null ? ldap_errno($this->link) : self::SERVER_DOWN;
        $diagnostic = '';
        if ($this->link !== null) {
            @ldap_get_option($this->link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
        }
        $diagnostic = is_string($diagnostic) ? $diagnostic : '';
        $tlsText = preg_match('/\b(tls|ssl|certificate|x509|handshake)\b/i', $diagnostic) === 1;
        $message = $what.': '.$errno.' '.ldap_err2str($errno);

        if ($errno === self::INVALID_CREDENTIALS) {
            $data = preg_match('/\bdata ([0-9a-f]{3,4})\b/i', $diagnostic, $m) === 1 ? strtolower($m[1]) : null;

            return new DirectoryException(in_array($data, self::AD_RESTRICTED, true)
                ? DirectoryException::ACCOUNT_RESTRICTED : DirectoryException::INVALID_CREDENTIALS, $message.($data ? ' (data '.$data.')' : ''));
        }
        if (in_array($errno, [self::SERVER_DOWN, self::TIMEOUT, self::CONNECT_ERROR], true)) {
            $tls = $this->config !== null && $this->config->tls !== DirectorySettings::TLS_NONE && ($tlsText || $starttls && $errno !== self::SERVER_DOWN);

            return new DirectoryException($tls ? DirectoryException::TLS : DirectoryException::UNREACHABLE, $message);
        }
        if ($starttls || $tlsText) {
            return new DirectoryException(DirectoryException::TLS, $message);
        }

        return new DirectoryException($search ? DirectoryException::SEARCH_FAILED : DirectoryException::INVALID_CREDENTIALS, $message);
    }

    /**
     * Global TLS options for the next connection.
     *
     * @return array<int, int|string>
     */
    private function tlsOptions(DirectoryConfig $config): array
    {
        $locations = openssl_get_cert_locations();
        $file = (string) ($locations['default_cert_file'] ?? '');
        $dir = (string) ($locations['default_cert_dir'] ?? '');
        if ($config->tls !== DirectorySettings::TLS_NONE && $config->verify && $config->caCert !== '') {
            // Own CA: a private directory with only this file, so the system store is not consulted.
            $dir = $this->writeCaDir($config->caCert);
            $file = $dir.'/ca.pem';
        }

        return [
            LDAP_OPT_X_TLS_REQUIRE_CERT => $config->verify ? LDAP_OPT_X_TLS_HARD : LDAP_OPT_X_TLS_NEVER,
            LDAP_OPT_X_TLS_CACERTFILE => $file,
            LDAP_OPT_X_TLS_CACERTDIR => $dir,
        ];
    }

    private function writeCaDir(string $pem): string
    {
        $dir = sys_get_temp_dir().'/portal-ldap-ca-'.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700) || file_put_contents($dir.'/ca.pem', $pem) === false) {
            throw new DirectoryException(DirectoryException::TLS, 'CA certificate file could not be written');
        }

        return $this->caDir = $dir;
    }
}
