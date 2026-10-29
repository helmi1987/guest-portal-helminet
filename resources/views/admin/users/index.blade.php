<x-layouts.admin :title="__('portal.users.title')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.users.title') }}</h1>
        <a href="{{ route('admin.users.create') }}" class="btn">{{ __('portal.users.create') }}</a>
    </div>
    <div class="card">
        <table class="data-table">
            <thead><tr><th>{{ __('portal.users.name') }}</th><th>{{ __('portal.auth.email') }}</th><th>{{ __('portal.users.role') }}</th><th>{{ __('portal.users.two_factor') }}</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td><a href="{{ route('admin.users.edit', $user) }}" class="underline">{{ $user->name }}</a></td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->role->label() }}</td>
                        <td>{{ $user->hasTwoFactor() ? __('portal.users.two_factor_on') : __('portal.users.two_factor_off') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.admin>
