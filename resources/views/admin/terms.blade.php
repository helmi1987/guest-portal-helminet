<x-layouts.admin :title="__('portal.terms.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.terms.title') }}</h1>
    <p class="mb-6 text-sm text-slate-600">{{ __('portal.terms.help') }}</p>
    @if ($missing !== [])
        <form method="POST" action="{{ route('admin.terms.publish-defaults') }}" class="mb-6">
            @csrf
            <button type="submit" class="btn">{{ __('portal.terms.publish_defaults') }}</button>
        </form>
    @endif

    <div class="flex flex-col gap-6">
        @foreach (config('portal.locales') as $locale)
            @php($list = $versions->get($locale, collect()))
            {{-- Prefilled with the default text while nothing is published, or on ?default=<locale>. --}}
            @php($prefillDefault = $list->isEmpty() || $load === $locale)
            <section class="card" id="terms-{{ $locale }}">
                <h2 class="mb-2 text-lg font-semibold">{{ __('portal.languages.'.$locale) }}</h2>
                <p class="mb-3 text-sm text-slate-500">
                    @if ($list->isEmpty())
                        {{ __('portal.terms.none') }}
                    @else
                        {{ __('portal.terms.current') }}: {{ $list->first()->number }} ({{ \App\Support\LocalTime::format($list->first()->published_at) }})
                    @endif
                </p>
                @if ($list->isEmpty())
                    <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" data-terms-default-hint>{{ __('portal.terms.default_hint') }}</p>
                @elseif ($load === $locale)
                    <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" data-terms-default-loaded>{{ __('portal.terms.default_loaded') }}</p>
                @endif
                <form method="POST" action="{{ route('admin.terms.update', $locale) }}" class="flex flex-col gap-3">
                    @csrf
                    <textarea name="body" rows="{{ $prefillDefault ? 16 : 8 }}" lang="{{ $locale }}" required maxlength="20000"
                              class="field font-mono text-sm">{{ $prefillDefault ? $defaults[$locale] : $list->first()->body }}</textarea>
                    <p class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn">{{ __('portal.terms.publish') }}</button>
                        @if ($list->isNotEmpty() && $load !== $locale)
                            <a href="{{ route('admin.terms', ['default' => $locale]) }}#terms-{{ $locale }}" class="btn-light">{{ __('portal.terms.load_default') }}</a>
                        @endif
                    </p>
                </form>
                @if ($list->isNotEmpty())
                    <details class="mt-4 text-sm">
                        <summary class="cursor-pointer">{{ __('portal.terms.history') }}</summary>
                        <table class="data-table mt-2">
                            <thead><tr><th>{{ __('portal.terms.version') }}</th><th>{{ __('portal.terms.published_at') }}</th><th>{{ __('portal.terms.acceptances') }}</th></tr></thead>
                            <tbody>
                                @foreach ($list as $version)
                                    <tr><td>{{ $version->number }}</td><td>{{ \App\Support\LocalTime::format($version->published_at, 'Y-m-d H:i') }}</td><td>{{ $version->acceptances_count }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.admin>
