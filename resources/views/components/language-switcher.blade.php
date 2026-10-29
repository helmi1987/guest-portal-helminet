@php
    // Links carry the current page so switching works without a Referer; POST results go to the portal.
    $return = request()->isMethod('GET') ? request()->getRequestUri() : route('guest.portal', absolute: false);
@endphp
<nav class="gp-lang-switch flex gap-1 text-sm" aria-label="{{ __('portal.language') }}">
    @foreach (config('portal.locales') as $code)
        <a href="{{ route('guest.locale', ['locale' => $code, 'r' => $return]) }}" hreflang="{{ $code }}" lang="{{ $code }}"
           @class(['gp-lang-link rounded px-2.5 py-1.5 uppercase', 'gp-lang-active bg-(--gp-accent) text-(--gp-accent-contrast)' => app()->getLocale() === $code, 'opacity-75 hover:bg-[color-mix(in_srgb,var(--gp-text)_12%,transparent)]' => app()->getLocale() !== $code])
           @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $code }}</a>
    @endforeach
</nav>
