@props(['branding', 'theme' => true])
{{-- Favicon and theme stylesheet (colour variables + custom CSS) after the app stylesheet; no inline CSS. --}}
@if ($favicon = $branding->faviconUrl())
    <link rel="icon" href="{{ $favicon }}" type="{{ $branding->faviconType() }}">
@else
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.png" type="image/png" sizes="32x32">
@endif
@if ($theme && ($themeUrl = $branding->themeUrl()))
    <link rel="stylesheet" href="{{ $themeUrl }}">
@endif
