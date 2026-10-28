<nav class="flex gap-2 text-sm" aria-label="{{ __('portal.language') }}">
    @foreach (config('portal.locales') as $code)
        <a href="{{ route('guest.locale', $code) }}" hreflang="{{ $code }}" lang="{{ $code }}"
           @class(['rounded px-2 py-1 uppercase', 'bg-slate-900 text-white' => app()->getLocale() === $code, 'text-slate-600 hover:bg-slate-200' => app()->getLocale() !== $code])
           @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $code }}</a>
    @endforeach
</nav>
