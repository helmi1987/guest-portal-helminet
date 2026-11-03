<?php

namespace App\Auth\Directory;

use RuntimeException;

/**
 * Failure of a directory operation (CONCEPT.md §7.2 E). The reason decides what the guest or the admin
 * sees; the message is for the application log and never contains a password.
 */
final class DirectoryException extends RuntimeException
{
    /** Server not reachable (connect error, timeout, extension missing). */
    public const UNREACHABLE = 'unreachable';

    /** TLS handshake or certificate check failed (ldaps or StartTLS). */
    public const TLS = 'tls';

    /** Wrong user name or password (bind result 49 without a special AD reason). */
    public const INVALID_CREDENTIALS = 'invalid_credentials';

    /** Account disabled, locked, expired or with an expired password (AD data codes 532, 533, 701, 773, 775). */
    public const ACCOUNT_RESTRICTED = 'account_restricted';

    /** The service account was rejected (bind DN or password wrong). */
    public const SERVICE_BIND_FAILED = 'service_bind_failed';

    /** The user search failed (base DN, filter, permissions). */
    public const SEARCH_FAILED = 'search_failed';

    /** No entry matches the user filter. */
    public const NOT_FOUND = 'not_found';

    /** More than one entry matches the user filter. */
    public const AMBIGUOUS = 'ambiguous';

    /** The user is not a member of the required group. */
    public const NOT_MEMBER = 'not_member';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    /** The directory itself is not usable right now (the guest sees "unavailable", not "wrong password"). */
    public function unavailable(): bool
    {
        return in_array($this->reason, [self::UNREACHABLE, self::TLS, self::SERVICE_BIND_FAILED, self::SEARCH_FAILED], true);
    }
}
