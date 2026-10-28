<x-layouts.guest>
    <section class="rounded-lg bg-white p-6 shadow">
        <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.guest.connecting') }}</h1>
        {{-- One-time credentials (valid ~60 s) are submitted to the router's login URL. --}}
        <form id="handoff" method="POST" action="{{ $url }}">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 font-medium text-white">{{ __('portal.guest.continue') }}</button>
        </form>
    </section>
    <script>document.getElementById('handoff').submit();</script>
</x-layouts.guest>
