<section class="gp-card gp-error card">
    <h1 class="gp-card-title mb-2 text-xl font-semibold">{{ __($key.'.title') }}</h1>
    <p class="gp-intro opacity-80">{{ __($key.'.text') }}</p>
    @if ($page['retryAfter'] !== null)
        <p class="gp-retry-after mt-2 text-sm opacity-80">{{ trans_choice('portal.http_errors.retry_after', $page['retryAfter'], ['seconds' => $page['retryAfter']]) }}</p>
    @endif
    <p class="mt-4"><a href="{{ $page['back'] }}" class="gp-button btn">{{ __($page['kind'] === '419' ? 'portal.http_errors.reload' : 'portal.http_errors.back') }}</a></p>
    <p class="gp-error-code mt-4 text-xs opacity-60">{{ __('portal.http_errors.code', ['code' => $page['status']]) }}</p>
</section>
