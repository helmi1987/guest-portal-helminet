<x-layouts.admin :title="__('portal.terms.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.terms.title') }}</h1>
    <p class="mb-6 text-sm text-slate-600">{{ __('portal.terms.help') }}</p>

    <div class="flex flex-col gap-6">
        @foreach (config('portal.locales') as $locale)
            @php($list = $versions->get($locale, collect()))
            <section class="card" id="terms-{{ $locale }}">
                <h2 class="mb-2 text-lg font-semibold">{{ __('portal.languages.'.$locale) }}</h2>
                <p class="mb-3 text-sm text-slate-500">
                    @if ($list->isEmpty())
                        {{ __('portal.terms.none') }}
                    @else
                        {{ __('portal.terms.current') }}: {{ $list->first()->number }} ({{ $list->first()->published_at->format('Y-m-d H:i') }})
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.terms.update', $locale) }}" class="flex flex-col gap-3">
                    @csrf
                    <textarea name="body" rows="8" lang="{{ $locale }}" required maxlength="20000"
                              class="field font-mono text-sm">{{ $list->first()?->body }}</textarea>
                    <p><button type="submit" class="btn">{{ __('portal.terms.publish') }}</button></p>
                </form>
                @if ($list->isNotEmpty())
                    <details class="mt-4 text-sm">
                        <summary class="cursor-pointer">{{ __('portal.terms.history') }}</summary>
                        <table class="data-table mt-2">
                            <thead><tr><th>{{ __('portal.terms.version') }}</th><th>{{ __('portal.terms.published_at') }}</th><th>{{ __('portal.terms.acceptances') }}</th></tr></thead>
                            <tbody>
                                @foreach ($list as $version)
                                    <tr><td>{{ $version->number }}</td><td>{{ $version->published_at->format('Y-m-d H:i') }}</td><td>{{ $version->acceptances_count }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.admin>
