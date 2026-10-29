@props(['ok', 'label'])
<span @class(['rounded px-2 py-0.5 text-xs font-medium', 'bg-green-100 text-green-800' => $ok, 'bg-slate-200 text-slate-600' => ! $ok])>{{ $label }}</span>
