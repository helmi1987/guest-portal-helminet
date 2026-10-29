<?php

namespace App\Approval;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Support\Duration;
use App\Support\LocalTime;

/**
 * Localized notification texts (language: admin setting "notification_locale").
 */
class ApprovalMessage
{
    public static function title(): string
    {
        return __('portal.approvals.notify.title', [], ApprovalSettings::locale());
    }

    /** @return list<string> */
    public static function lines(ApprovalRequest $request): array
    {
        $locale = ApprovalSettings::locale();

        return [
            __('portal.approvals.notify.guest', ['name' => $request->guestName()], $locale),
            __('portal.approvals.notify.site', ['site' => $request->site?->name], $locale),
            __('portal.approvals.notify.device', ['mac' => $request->mac], $locale),
            __('portal.approvals.notify.valid_until', ['time' => LocalTime::format($request->expires_at, 'H:i')], $locale),
        ];
    }

    public static function decision(ApprovalRequest $request): string
    {
        $locale = ApprovalSettings::locale();

        return match ($request->currentStatus()) {
            ApprovalStatus::Approved => __('portal.approvals.notify.approved', [
                'by' => $request->decided_by, 'duration' => Duration::format($request->duration_s)], $locale),
            ApprovalStatus::Denied => __('portal.approvals.notify.denied', ['by' => $request->decided_by], $locale),
            default => __('portal.approvals.notify.expired', [], $locale),
        };
    }

    public static function choiceLabel(string $choice): string
    {
        return __('portal.approvals.choices.'.$choice, [], ApprovalSettings::locale());
    }
}
