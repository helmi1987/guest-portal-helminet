@if ($errors->any())
    <ul class="gp-error rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
@endif
