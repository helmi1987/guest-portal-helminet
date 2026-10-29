<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Replicated;
use App\Support\Redact;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'totp_last_step', 'recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Replicated;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'totp_last_step' => 'integer',
            'recovery_codes' => 'array',
        ];
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->role === ($role instanceof Role ? $role : Role::from($role))) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** TOTP enrolled and confirmed. */
    public function hasTwoFactor(): bool
    {
        return $this->totp_confirmed_at !== null && $this->totp_secret !== null;
    }

    /**
     * Ends the user's other sessions after a security change (password, 2FA, deletion): new remember
     * token and all session rows except $keepSessionId (the acting user's own current session).
     */
    public function invalidateSessions(?string $keepSessionId = null): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $this->getKey())
            ->when($keepSessionId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->delete();
    }

    /** Phone numbers in full for admins, masked for managers (CONCEPT.md §9). */
    public function displayPhone(?string $phone): string
    {
        return $this->isAdmin() ? (string) $phone : Redact::phone($phone);
    }
}
