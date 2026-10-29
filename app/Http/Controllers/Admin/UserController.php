<?php

namespace App\Http\Controllers\Admin;

use App\Auth\TwoFactor;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin UI users (admin only, CONCEPT.md §9). Nobody can delete or demote themselves, and the last
 * admin always stays.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', ['users' => User::query()->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => new User(['role' => Role::Manager])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, new User);
        $user = User::create($data);
        Audit::log('user.created', $user, ['email' => $user->email, 'role' => $user->role->value]);

        return redirect()->route('admin.users.index')->with('status', __('portal.saved'));
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if (($data['password'] ?? null) === null) {
            unset($data['password']);
        }

        DB::transaction(function () use ($request, $user, $data) {
            if ($user->isAdmin() && $data['role'] !== Role::Admin->value) {
                $this->guardAdminRemoval($request, $user, 'role');
            }
            $user->update($data);
        });
        $changed = array_values(array_diff(array_keys($user->getChanges()), ['updated_at', 'version']));
        if (in_array('password', $changed, true)) {
            if ($user->is($request->user())) {
                $user->invalidateSessions($request->session()->getId());
                $request->session()->regenerate(true);
            } else {
                $user->invalidateSessions();
            }
        }
        Audit::log('user.updated', $user, ['changed' => $changed, 'role' => $user->role->value]);

        return redirect()->route('admin.users.index')->with('status', __('portal.saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user) {
            if ($user->is($request->user())) {
                throw ValidationException::withMessages(['user' => __('portal.users.not_self')]);
            }
            if ($user->isAdmin()) {
                $this->guardAdminRemoval($request, $user, 'user');
            }
            $user->invalidateSessions();
            $user->delete();
        });
        Audit::log('user.deleted', $user, ['email' => $user->email]);

        return redirect()->route('admin.users.index')->with('status', __('portal.deleted'));
    }

    /** Remove another user's 2FA, e.g. lost phone without recovery codes. */
    public function resetTwoFactor(Request $request, User $user, TwoFactor $twoFactor): RedirectResponse
    {
        // Own 2FA only via the profile (password + code).
        abort_if($user->is($request->user()), 403);
        $request->validate(['current_password' => ['required', 'current_password:web']]);
        $twoFactor->disable($user);
        $user->invalidateSessions();
        Audit::log('two_factor.reset', $user);

        return back()->with('status', __('portal.users.two_factor_reset'));
    }

    private function guardAdminRemoval(Request $request, User $user, string $field): void
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([$field => __('portal.users.not_self')]);
        }
        if (User::query()->where('role', Role::Admin)->lockForUpdate()->count() <= 1) {
            throw ValidationException::withMessages([$field => __('portal.users.last_admin')]);
        }
    }

    private function validated(Request $request, User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Soft-deleted users keep their address (unique index), hence no whereNull here.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => [$user->exists ? 'nullable' : 'required', 'confirmed', Password::min(12)->max(255)],
        ]);
    }
}
