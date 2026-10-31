@props(['ok', 'label'])
<span @class(['gp-badge', 'gp-badge-ok' => $ok, 'gp-badge-neutral' => ! $ok])>{{ $label }}</span>
