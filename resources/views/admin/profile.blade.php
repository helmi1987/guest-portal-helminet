<x-layouts.admin :title="__('portal.profile.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.profile.title') }}</h1>

    @if (session('recovery_codes'))
        <section class="card mb-6 border-2 border-amber-400">
            <h2 class="mb-2 text-lg font-semibold">{{ __('portal.profile.recovery_codes') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('portal.profile.recovery_codes_help') }}</p>
            <ul class="grid grid-cols-2 gap-2 font-mono text-sm md:grid-cols-4">
                @foreach (session('recovery_codes') as $code)<li>{{ $code }}</li>@endforeach
            </ul>
        </section>
    @endif

    <section class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.profile.two_factor') }}</h2>
        @if ($user->hasTwoFactor())
            <p class="mb-4 text-sm">{{ __('portal.profile.two_factor_active', ['date' => $user->totp_confirmed_at->format('d.m.Y'), 'count' => $recoveryLeft]) }}</p>
            <p class="mb-4 text-xs text-slate-500">{{ __('portal.profile.two_factor_manage_help') }}</p>
            @foreach (['recovery' => 'POST', 'disable' => 'DELETE'] as $action => $method)
                <form method="POST" action="{{ route('admin.profile.two-factor.'.$action) }}" class="mb-4 grid gap-4 md:grid-cols-3">
                    @csrf @method($method)
                    <label class="label">{{ __('portal.profile.current_password') }}
                        <input name="current_password" type="password" required autocomplete="current-password" class="field"></label>
                    <label class="label">{{ __('portal.auth.two_factor_code') }}
                        <input name="code" required maxlength="32" autocomplete="one-time-code" class="field font-mono"></label>
                    <div class="flex items-end">
                        @if ($action === 'disable')
                            <button class="btn-danger" @disabled($required)>{{ __('portal.profile.two_factor_disable') }}</button>
                        @else
                            <button class="btn-light">{{ __('portal.profile.recovery_regenerate') }}</button>
                        @endif
                    </div>
                </form>
            @endforeach
            @if ($required)<p class="text-xs text-slate-500">{{ __('portal.profile.two_factor_required') }}</p>@endif
        @elseif ($pending)
            <p class="mb-4 text-sm">{{ __('portal.profile.two_factor_scan') }}</p>
            <div class="mb-4 flex flex-wrap items-center gap-6">
                <div class="h-48 w-48 bg-white">{!! $qr !!}</div>
                <div class="text-sm">
                    <p class="mb-1">{{ __('portal.profile.two_factor_secret') }}</p>
                    <p class="font-mono break-all">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.profile.two-factor.confirm') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="label">{{ __('portal.auth.two_factor_code') }}
                    <input name="code" required maxlength="16" inputmode="numeric" autocomplete="one-time-code" class="field font-mono"></label>
                <button class="btn">{{ __('portal.profile.two_factor_confirm') }}</button>
            </form>
        @else
            <p class="mb-4 text-sm">{{ __('portal.profile.two_factor_off') }}</p>
            <form method="POST" action="{{ route('admin.profile.two-factor') }}">
                @csrf
                <button class="btn">{{ __('portal.profile.two_factor_enable') }}</button>
            </form>
        @endif
    </section>

    <form method="POST" action="{{ route('admin.profile.password') }}" class="card grid gap-4 md:grid-cols-3">
        @csrf
        <h2 class="text-lg font-semibold md:col-span-3">{{ __('portal.profile.password') }}</h2>
        <label class="label">{{ __('portal.profile.current_password') }}
            <input name="current_password" type="password" required autocomplete="current-password" class="field"></label>
        <label class="label">{{ __('portal.users.password') }}
            <input name="password" type="password" required minlength="12" autocomplete="new-password" class="field"></label>
        <label class="label">{{ __('portal.users.password_confirm') }}
            <input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="field"></label>
        <div class="md:col-span-3"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>
</x-layouts.admin>
