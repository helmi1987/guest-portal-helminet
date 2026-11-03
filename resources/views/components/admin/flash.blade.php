@if (session('status'))
    <p class="mb-4 rounded bg-green-100 px-4 py-2 text-sm text-green-800">{{ session('status') }}</p>
@endif
@if (session('warning'))
    <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-900" role="status">{{ session('warning') }}
        @if (is_array($link = session('warning_link')))<a href="{{ $link[0] }}" class="ml-1 font-medium underline">{{ $link[1] }}</a>@endif</p>
@endif
@if ($errors->any())
    <ul class="mb-4 rounded bg-red-100 px-4 py-2 text-sm text-red-800">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
@endif
