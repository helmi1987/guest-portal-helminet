<x-layouts.admin :title="$user->exists ? $user->name : __('portal.users.create')">
    <h1 class="mb-4 text-2xl font-semibold">{{ $user->exists ? $user->name : __('portal.users.create') }}</h1>

    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <label class="label">{{ __('portal.users.name') }}
            <input name="name" value="{{ old('name', $user->name) }}" required maxlength="255" class="field"></label>
        <label class="label">{{ __('portal.auth.email') }}
            <input name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="off" class="field"></label>
        <label class="label">{{ __('portal.users.role') }}
            <select name="role" class="field">
                @foreach (\App\Enums\Role::cases() as $role)
                    <option value="{{ $role->value }}" @selected(old('role', $user->role?->value) === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
            <span class="text-xs text-slate-500">{{ __('portal.users.role_help') }}</span></label>
        <div></div>
        <label class="label">{{ __('portal.users.password') }}
            <input name="password" type="password" minlength="12" @if (! $user->exists) required @endif autocomplete="new-password" class="field">
            <span class="text-xs text-slate-500">{{ $user->exists ? __('portal.users.password_keep') : __('portal.users.password_help') }}</span></label>
        <label class="label">{{ __('portal.users.password_confirm') }}
            <input name="password_confirmation" type="password" minlength="12" autocomplete="new-password" class="field"></label>
        <div class="md:col-span-2 flex gap-2">
            <button class="btn">{{ __('portal.save') }}</button>
            <a href="{{ route('admin.users.index') }}" class="btn-light">{{ __('portal.back') }}</a>
        </div>
    </form>

    @if ($user->exists && ! $user->is(auth()->user()))
        <section class="card flex flex-wrap gap-4">
            @if ($user->hasTwoFactor())
                <form method="POST" action="{{ route('admin.users.reset-two-factor', $user) }}" class="flex flex-wrap items-end gap-2" onsubmit="return confirm(@js(__('portal.confirm')))">
                    @csrf
                    <label class="label">{{ __('portal.users.your_password') }}
                        <input name="current_password" type="password" required autocomplete="current-password" class="field"></label>
                    <button class="btn-light">{{ __('portal.users.reset_two_factor') }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                @csrf @method('DELETE')
                <button class="btn-danger">{{ __('portal.delete') }}</button>
            </form>
        </section>
    @endif
</x-layouts.admin>
