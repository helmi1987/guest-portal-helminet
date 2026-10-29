<?php

namespace App\Auth\Oidc;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps verified OIDC claims to an admin UI user (CONCEPT.md §9):
 * - role from the groups claim (admin group wins over manager group); no matching group: denied;
 * - known users are matched by "<iss> <sub>"; on the first login an existing user is linked by
 *   email only when linking is enabled and `email_verified` is true; otherwise a user is created
 *   (verified email required, unusable random password);
 * - the role is re-synced on every login; demoting the last admin is denied;
 * - deleted (soft-deleted) users stay deleted: their login is denied.
 */
class OidcUserResolver
{
    /** The last resolve() linked an existing local user (first OIDC login of that user). */
    public bool $linked = false;

    /** The last resolve() changed the user's role. */
    public bool $roleChanged = false;

    /** @param  array<string, mixed>  $config  OidcSettings::all() */
    public function __construct(private readonly array $config) {}

    /** @param  array<string, mixed>  $claims  ID token claims merged with userinfo claims */
    public function resolve(array $claims): User
    {
        $subject = $claims['iss'].' '.$claims['sub'];
        $role = $this->role($claims);
        $this->linked = $this->roleChanged = false;
        $verified = ($claims['email_verified'] ?? null) === true;
        $email = is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            ? mb_substr($claims['email'], 0, 255) : null;

        return DB::transaction(function () use ($subject, $role, $email, $verified, $claims) {
            $user = User::withTrashed()->where('oidc_subject', $subject)->first();
            if ($user !== null && ! $user->trashed() && $role === null) {
                // Linked user lost all portal groups: end the user's sessions as well (OidcController).
                throw new OidcException('no_group', 'No matching group', $user, invalidateSessions: true);
            }
            if ($user === null && $email !== null) {
                $user = User::withTrashed()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();
                if ($user !== null) {
                    if ($user->trashed()) {
                        throw new OidcException('user_deleted', 'Matching user is deleted', $user);
                    }
                    if ($user->oidc_subject !== null) {
                        throw new OidcException('subject_mismatch', 'User is linked to another OIDC subject', $user);
                    }
                    if (! $this->config['link_by_email']) {
                        throw new OidcException('link_disabled', 'A local user has this email; linking by email is disabled', $user);
                    }
                    if (! $verified) {
                        throw new OidcException('email_unverified', 'Email not verified by the identity provider; user not linked', $user);
                    }
                    if ($role === null) {
                        throw new OidcException('no_group', 'No matching group', $user);
                    }
                    $user->forceFill(['oidc_subject' => $subject])->save();
                    $this->linked = true;
                    Audit::log('user.oidc_linked', $user, [], $user);
                }
            }
            if ($user?->trashed()) {
                throw new OidcException('user_deleted', 'User is deleted', $user);
            }
            if ($role === null) {
                throw new OidcException('no_group', 'No matching group', $user);
            }

            if ($user === null) {
                if ($email === null) {
                    throw new OidcException('no_email', 'No email claim');
                }
                if (! $verified) {
                    throw new OidcException('email_unverified', 'Email not verified by the identity provider; user not created');
                }
                $user = new User([
                    'name' => $this->name($claims, $email),
                    'email' => $email,
                    // Local password login stays possible only after an admin sets a password.
                    'password' => Str::random(64),
                    'role' => $role,
                ]);
                $user->forceFill(['oidc_subject' => $subject])->save();
                Audit::log('user.created', $user, ['email' => $email, 'role' => $role->value, 'source' => 'oidc'], $user);

                return $user;
            }

            if ($user->role !== $role) {
                if ($user->isAdmin() && User::query()->where('role', Role::Admin)->lockForUpdate()->count() <= 1) {
                    throw new OidcException('last_admin', 'Demotion of the last admin denied', $user);
                }
                $from = $user->role->value;
                $user->forceFill(['role' => $role])->save();
                $this->roleChanged = true;
                Audit::log('user.role_synced', $user, ['from' => $from, 'to' => $role->value], $user);
            }

            return $user;
        });
    }

    /** @param  array<string, mixed>  $claims */
    public function role(array $claims): ?Role
    {
        $groups = $claims[$this->config['groups_claim']] ?? [];
        $groups = array_filter(is_array($groups) ? $groups : [$groups], 'is_string');

        return match (true) {
            $this->config['admin_group'] !== '' && in_array($this->config['admin_group'], $groups, true) => Role::Admin,
            $this->config['manager_group'] !== '' && in_array($this->config['manager_group'], $groups, true) => Role::Manager,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $claims */
    private function name(array $claims, string $email): string
    {
        foreach (['name', 'preferred_username'] as $claim) {
            if (is_string($claims[$claim] ?? null) && trim($claims[$claim]) !== '') {
                return mb_substr(trim($claims[$claim]), 0, 255);
            }
        }

        return $email;
    }
}
