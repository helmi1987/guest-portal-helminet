<div class="mx-auto mt-16 max-w-sm rounded-lg bg-(--gp-surface) p-6 shadow-(--gp-card-shadow)">
    <h1 class="mb-4 text-xl font-semibold">{{ __('portal.auth.title') }}</h1>
    @if (session('oidc_error'))
        <p class="mb-4 rounded bg-red-100 px-3 py-2 text-sm text-red-800">{{ session('oidc_error') }}</p>
    @endif
    @if ($twoFactor)
        <form wire:submit="verify" class="flex flex-col gap-4">
            <p class="text-sm text-slate-600">{{ __('portal.auth.two_factor_help') }}</p>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('portal.auth.two_factor_code') }}</span>
                <input type="text" wire:model="code" autocomplete="one-time-code" inputmode="text" maxlength="32" required autofocus
                       class="rounded border border-slate-300 px-3 py-2 font-mono">
                @error('code') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <button type="submit" class="gp-button btn">{{ __('portal.auth.two_factor_submit') }}</button>
            <button type="button" wire:click="cancel" class="text-sm underline">{{ __('portal.back') }}</button>
        </form>
    @else
        <form wire:submit="login" class="flex flex-col gap-4">
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('portal.auth.email') }}</span>
                <input type="email" wire:model="email" autocomplete="username" required autofocus
                       class="rounded border border-slate-300 px-3 py-2">
                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('portal.auth.password') }}</span>
                <input type="password" wire:model="password" autocomplete="current-password" required
                       class="rounded border border-slate-300 px-3 py-2">
                @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <button type="submit" class="gp-button btn">{{ __('portal.auth.submit') }}</button>
        </form>
        @if ($oidcLabel !== null)
            <div class="mt-4 border-t border-slate-200 pt-4">
                <a href="{{ route('oidc.redirect') }}" class="block rounded border border-slate-300 px-4 py-2 text-center font-medium">{{ __('portal.oidc.button', ['label' => $oidcLabel]) }}</a>
            </div>
        @endif
    @endif
</div>
