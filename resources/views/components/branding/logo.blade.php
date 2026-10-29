@props(['branding', 'size' => 'h-8'])
{{-- Uploaded logo, or the built-in default (inline SVG in currentColor, follows --gp-accent via .gp-logo). --}}
@if ($url = $branding->logoUrl())
    <img src="{{ $url }}" alt="" {{ $attributes->class(['gp-logo w-auto max-w-40 object-contain', $size]) }}>
@else
    {!! str_replace('<svg ', '<svg class="'.e(trim('gp-logo gp-logo-default shrink-0 '.$size.' w-auto '.$attributes->get('class'))).'" ', file_get_contents(resource_path('images/default-logo.svg'))) !!}
@endif
