{{-- Settings: guest login methods and social login providers (CONCEPT.md §9, decision 2026-11-03).
     Included by admin/settings.blade.php. --}}
<form method="POST" action="{{ route('admin.settings.login-methods') }}" class="card mb-6 grid gap-4" id="login-methods">
    @csrf
    <h2 class="text-lg font-semibold">{{ __('portal.settings.login_methods.title') }}</h2>
    <p class="text-xs text-slate-500">{{ __('portal.settings.login_methods.help') }}</p>
    @foreach (\App\Auth\Social\LoginMethods::ALL as $method)
        <label class="flex items-start gap-2 text-sm" data-login-method="{{ $method }}">
            <input type="hidden" name="methods[{{ $method }}]" value="0">
            <input type="checkbox" name="methods[{{ $method }}]" value="1" @checked(old('methods.'.$method, $loginMethods[$method])) class="mt-0.5">
            <span>{{ __('portal.settings.login_methods.methods.'.$method) }}
                @isset($loginMethodHints[$method])
                    <br><span class="text-xs text-amber-700" data-login-method-hint>{{ __($loginMethodHints[$method]) }}</span>
                @endisset
            </span>
        </label>
    @endforeach
    @error('methods')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
    <div><button class="btn">{{ __('portal.save') }}</button></div>
</form>

<form method="POST" action="{{ route('admin.settings.social') }}" class="card mb-6 grid gap-4" id="social-login">
    @csrf
    <h2 class="text-lg font-semibold">{{ __('portal.settings.social.title') }}</h2>
    <p class="text-xs text-slate-500">{{ __('portal.settings.social.help') }} {{ __('portal.settings.social.federation_hint') }}</p>
    <label class="label md:max-w-xs">{{ __('portal.settings.social.duration') }}
        <input name="social_duration_hours" type="number" step="0.25" min="0.25" max="8760" value="{{ old('social_duration_hours', $socialDurationHours) }}" required class="field">
        @error('social_duration_hours')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <p class="text-xs text-slate-500">{{ __('portal.settings.social.origins_help', ['count' => count($socialOrigins)]) }}
        {{ __('portal.settings.social.walled_garden') }}</p>

    @php($check = session('social_check'))
    @foreach ($social as $provider => $cfg)
        <div class="rounded border border-slate-300 p-4" id="social-{{ $provider }}" data-social-provider="{{ $provider }}">
            <h3 class="mb-3 font-semibold">{{ __('portal.settings.social.providers.'.$provider) }}</h3>

            @if (is_array($check) && ($check['provider'] ?? null) === $provider)
                <p role="status" data-social-check="{{ $check['status'] }}" @class(['mb-3 rounded px-4 py-2 text-sm',
                    'bg-green-100 text-green-800' => $check['status'] === 'ok',
                    'bg-red-100 text-red-800' => $check['status'] === 'rejected',
                    'bg-amber-100 text-amber-800' => ! in_array($check['status'], ['ok', 'rejected'], true)])>{{ $check['message'] }}</p>
            @endif

            <p class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs" data-social-status>
                @if ($cfg['secret_broken'])
                    <span class="text-red-700">{{ __('portal.settings.social.secret_broken') }}</span>
                @elseif ($cfg['has_secret'])
                    <span class="text-green-700">{{ __('portal.settings.social.secret_stored') }}</span>
                @else
                    <span class="text-slate-500">{{ __('portal.settings.social.secret_none') }}</span>
                @endif
                @if ($cfg['ready'])
                    <span class="text-green-700" data-social-ready>{{ __('portal.settings.social.ready') }}</span>
                @else
                    <span class="text-slate-600" data-social-missing>{{ __('portal.settings.social.not_ready', ['items' =>
                        collect($cfg['missing'])->map(fn ($m) => __('portal.settings.social.missing.'.$m))->implode(', ')]) }}</span>
                @endif
                @if ($cfg['ready'] && ! $loginMethods['social'])
                    <span class="text-amber-700" data-social-switch-off>{{ __('portal.settings.social.switch_off') }}</span>
                @endif
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-start gap-2 text-sm md:col-span-2">
                    <input type="hidden" name="providers[{{ $provider }}][enabled]" value="0">
                    <input type="checkbox" name="providers[{{ $provider }}][enabled]" value="1" @checked(old('providers.'.$provider.'.enabled', $cfg['enabled'])) class="mt-0.5">
                    <span>{{ __('portal.settings.social.enabled') }}</span>
                </label>
                <label class="label">{{ __($provider === 'apple' ? 'portal.settings.social.services_id' : 'portal.settings.social.client_id') }}
                    <input name="providers[{{ $provider }}][client_id]" value="{{ old('providers.'.$provider.'.client_id', $cfg['client_id']) }}" autocomplete="off" class="field">
                    @error('providers.'.$provider.'.client_id')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                @if ($provider === 'apple')
                    <label class="label">{{ __('portal.settings.social.team_id') }}
                        <input name="providers[apple][team_id]" value="{{ old('providers.apple.team_id', $cfg['team_id']) }}" maxlength="10" autocomplete="off" class="field font-mono">
                        @error('providers.apple.team_id')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="label">{{ __('portal.settings.social.key_id') }}
                        <input name="providers[apple][key_id]" value="{{ old('providers.apple.key_id', $cfg['key_id']) }}" maxlength="10" autocomplete="off" class="field font-mono">
                        @error('providers.apple.key_id')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="label md:col-span-2">{{ __('portal.settings.social.apple_key') }}
                        <textarea name="providers[apple][secret]" rows="5" autocomplete="off" spellcheck="false" class="field font-mono text-xs"
                            placeholder="{{ $cfg['has_secret'] ? __('portal.channels.secret_keep') : '-----BEGIN PRIVATE KEY-----' }}"></textarea>
                        <span class="text-xs text-slate-500">{{ __('portal.settings.social.apple_key_help') }}</span>
                @else
                    <label class="label">{{ __('portal.settings.social.client_secret') }}
                        <input type="password" name="providers[{{ $provider }}][secret]" value="" autocomplete="new-password"
                            placeholder="{{ $cfg['has_secret'] ? __('portal.channels.secret_keep') : '' }}" class="field">
                @endif
                    @error('providers.'.$provider.'.secret')<span class="text-xs text-red-700">{{ $message }}</span>@enderror
                    @if ($cfg['has_secret'] || $cfg['secret_broken'])
                        <span class="flex items-center gap-2 text-xs text-slate-500">
                            <input type="hidden" name="providers[{{ $provider }}][secret_clear]" value="0">
                            <input type="checkbox" name="providers[{{ $provider }}][secret_clear]" value="1"> {{ __('portal.settings.social.secret_clear') }}</span>
                    @endif
                </label>
            </div>

            <div class="mt-4">
                @if ($cfg['redirect_uris'] !== [])
                    <x-admin.code :title="__('portal.settings.social.redirect_uris')" :code="implode(PHP_EOL, $cfg['redirect_uris'])" />
                @else
                    <p class="text-xs text-red-700">{{ __('portal.settings.social.no_origin') }}</p>
                @endif
                @if ($provider === 'github')
                    <p @class(['text-xs', 'text-amber-700' => count($socialOrigins) > 1, 'text-slate-500' => count($socialOrigins) <= 1]) data-github-single-callback>
                        {{ __('portal.settings.social.github_single_callback', ['uri' => $cfg['redirect_uris'][0] ?? '–']) }}</p>
                @else
                    <p class="text-xs text-slate-500">{{ __('portal.settings.social.redirect_uris_help') }}</p>
                @endif
            </div>

            <details class="mt-3 text-sm" data-social-guide>
                <summary class="cursor-pointer font-medium">{{ __('portal.settings.social.guide_title') }}</summary>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    @foreach ((array) __('portal.settings.social.guide.'.$provider) as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </details>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="submit" form="social-check-{{ $provider }}" class="btn-light">{{ __('portal.settings.social.check_button') }}</button>
                <span class="text-xs text-slate-500">{{ __('portal.settings.social.check_help') }}</span>
            </div>
        </div>
    @endforeach
    <div><button class="btn">{{ __('portal.save') }}</button></div>
</form>
@foreach (\App\Auth\Social\SocialSettings::PROVIDERS as $provider)
    <form method="POST" action="{{ route('admin.settings.social.check', $provider) }}" id="social-check-{{ $provider }}" class="hidden">@csrf</form>
@endforeach
