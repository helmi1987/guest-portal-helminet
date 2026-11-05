{{-- Expiry status of one provider secret (decision 2026-11-05, v0.31.0): $status from SecretExpiry::status(). --}}
@php($date = \App\Auth\Social\SecretExpiry::format($status['date']))
<span data-social-secret-status="{{ $status['level'] }}" @class([
    'text-green-700' => $status['level'] === 'ok',
    'text-amber-700' => $status['level'] === 'soon',
    'text-red-700' => $status['level'] === 'expired',
])>@if ($status['level'] === 'expired'){{ __('portal.social_secret.status.expired', ['date' => $date]) }}@elseif ($status['days'] === 0){{ __('portal.social_secret.status.today', ['date' => $date]) }}@elseif ($status['level'] === 'soon'){{ trans_choice('portal.social_secret.status.soon', $status['days'], ['date' => $date, 'days' => $status['days']]) }}@else{{ __('portal.social_secret.status.valid', ['date' => $date]) }}@endif</span>
