@php($fields = \App\Models\Channel::fieldsOf($channel->driver))
<x-layouts.admin :title="__('portal.channels.drivers.'.$channel->driver)">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.channels.drivers.'.$channel->driver) }}</h1>

    <form method="POST" action="{{ $channel->exists ? route('admin.channels.update', $channel) : route('admin.channels.store') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        @if ($channel->exists) @method('PUT') @else <input type="hidden" name="driver" value="{{ $channel->driver }}"> @endif
        <label class="label">{{ __('portal.sites.name') }}
            <input name="name" value="{{ old('name', $channel->name) }}" required maxlength="100" class="field"></label>
        <label class="flex items-center gap-2 self-end text-sm">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $channel->enabled))> {{ __('portal.channels.enabled') }}</label>
        @foreach ($fields as $field => $spec)
            @php($secret = $spec['secret'] ?? false)
            <label class="label">{{ __('portal.channels.fields.'.$field) }}
                <input name="config[{{ $field }}]" type="{{ $secret ? 'password' : 'text' }}" autocomplete="off"
                       value="{{ $secret ? '' : old('config.'.$field, $channel->conf($field)) }}"
                       placeholder="{{ $secret && $channel->conf($field) ? __('portal.channels.secret_keep') : '' }}" class="field">
                @if (\Illuminate\Support\Facades\Lang::has('portal.channels.field_help.'.$field))
                    <span class="text-xs text-slate-500">{{ __('portal.channels.field_help.'.$field) }}</span>
                @endif
            </label>
        @endforeach
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    @if ($channel->exists && $channel->kind === \App\Models\Channel::KIND_SMS)
        <form method="POST" action="{{ route('admin.channels.test', $channel) }}" class="card mb-6 flex flex-wrap items-end gap-2">
            @csrf
            <label class="label">{{ __('portal.channels.test_phone') }}
                <input name="phone" type="tel" required maxlength="30" placeholder="+41 79 000 00 00" class="field"></label>
            <button class="btn-light">{{ __('portal.channels.test') }}</button>
            @error('phone')<p class="w-full text-sm text-red-700">{{ $message }}</p>@enderror
            <p class="w-full text-xs text-slate-500">{{ __('portal.channels.test_help') }}</p>
        </form>
    @endif

    @if ($channel->exists)
        <form method="POST" action="{{ route('admin.channels.destroy', $channel) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
            @csrf @method('DELETE') <button class="btn-danger">{{ __('portal.delete') }}</button></form>
    @endif
</x-layouts.admin>
