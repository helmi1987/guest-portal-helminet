@php
    // Links carry the current page so switching works without a Referer; POST results go to the portal.
    $return = request()->isMethod('GET') ? request()->getRequestUri() : route('guest.portal', absolute: false);
@endphp
<nav class="flex gap-1 text-sm" aria-label="{{ __('portal.language') }}">
    @foreach (config('portal.locales') as $code)
        <a href="{{ route('guest.locale', ['locale' => $code, 'r' => $return]) }}" hreflang="{{ $code }}" lang="{{ $code }}"
           @class(['rounded px-2.5 py-1.5 uppercase', 'bg-slate-900 text-white' => app()->getLocale() === $code, 'text-slate-600 hover:bg-slate-200' => app()->getLocale() !== $code])
           @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $code }}</a>
    @endforeach
</nav>
