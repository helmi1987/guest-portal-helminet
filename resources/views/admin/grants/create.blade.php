<x-layouts.admin :title="__('portal.grants.create')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.grants.create') }}</h1>
    <form method="POST" action="{{ route('admin.grants.store') }}" class="card grid gap-4 md:grid-cols-2">
        @csrf
        <label class="label">{{ __('portal.grants.duration') }}
            <select name="duration" class="field">
                @foreach (array_keys(config('portal.duration_presets')) as $preset)
                    <option value="{{ $preset }}" @selected(old('duration', '1d') === $preset)>{{ __('portal.grants.presets.'.$preset) }}</option>
                @endforeach
                <option value="custom" @selected(old('duration') === 'custom')>{{ __('portal.grants.presets.custom') }}</option>
            </select></label>
        <label class="label">{{ __('portal.grants.custom_hours') }}
            <input type="number" step="0.25" min="0.25" name="custom_hours" value="{{ old('custom_hours') }}" class="field"></label>
        <label class="label">{{ __('portal.grants.expiry_mode') }}
            <select name="expiry_mode" class="field">
                @foreach (\App\Enums\ExpiryMode::cases() as $mode)
                    <option value="{{ $mode->value }}" @selected(old('expiry_mode', 'first_login') === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.grants.quantity') }}
            <input type="number" min="1" max="50" name="quantity" value="{{ old('quantity', 1) }}" required class="field"></label>
        @include('admin.grants._fields', ['grant' => null])
        <div class="md:col-span-2"><button class="btn">{{ __('portal.grants.create') }}</button></div>
    </form>
</x-layouts.admin>
