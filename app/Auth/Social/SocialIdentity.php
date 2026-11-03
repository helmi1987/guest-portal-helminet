<?php

namespace App\Auth\Social;

/**
 * Identity reported by a social login provider after a verified callback: provider, the provider's
 * subject (user ID), names as far as known and the email (CONCEPT.md §7.2 D). Kept in the session only
 * while the guest completes a missing name (10 minutes).
 */
final class SocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email,
    ) {}

    /** First and last name both known. */
    public function hasName(): bool
    {
        return $this->firstName !== '' && $this->lastName !== '';
    }

    public function withName(string $firstName, string $lastName): self
    {
        return new self($this->provider, $this->subject, $firstName, $lastName, $this->email);
    }

    /** @return array{provider: string, subject: string, first_name: string, last_name: string, email: ?string} */
    public function toArray(): array
    {
        return ['provider' => $this->provider, 'subject' => $this->subject, 'first_name' => $this->firstName,
            'last_name' => $this->lastName, 'email' => $this->email];
    }

    public static function fromArray(array $data): self
    {
        return new self((string) ($data['provider'] ?? ''), (string) ($data['subject'] ?? ''),
            (string) ($data['first_name'] ?? ''), (string) ($data['last_name'] ?? ''),
            is_string($data['email'] ?? null) ? $data['email'] : null);
    }

    /**
     * Name part as stored: trimmed, single spaces, at most 100 characters (like the login forms).
     */
    public static function clean(mixed $value): string
    {
        $value = is_string($value) || is_int($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return mb_substr($value, 0, 100);
    }

    /** Email as stored: a valid address of at most 191 characters, else null. */
    public static function cleanEmail(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && mb_strlen($value) <= 191 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
