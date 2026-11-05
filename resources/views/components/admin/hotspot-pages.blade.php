{{-- Download links of the site's hotspot pages (login.html; MikroTik also status, logout, alogin; decision 2026-11-05). --}}
@props(['site'])
@foreach (array_keys(\App\Http\Controllers\RouterLoginPageController::pages($site)) as $page)
    <a href="{{ route('admin.sites.login-page', [$site, $page]) }}" class="btn-light" data-hotspot-page="{{ $page }}">{{ __('portal.sites.download_page', ['file' => $page.'.html']) }}</a>
@endforeach
