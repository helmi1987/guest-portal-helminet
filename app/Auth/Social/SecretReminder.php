<?php

namespace App\Auth\Social;

use App\Approval\ApprovalSettings;
use App\Federation\Topology;
use App\Models\Channel;
use App\Models\LocalSetting;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reminders before a social login provider secret expires (CONCEPT.md §9, decision 2026-11-05, v0.31.0): at 90, 60,
 * 30, 14, 7, 3, 2, 1 and 0 days before the date and once after it, over every enabled notification channel (like
 * the router notifications). Each stage is sent once per provider and date: the local setting REMINDERS_KEY keeps
 * per provider the date and the last stage sent; another date starts over. Only the latest reached stage is sent
 * (after a pause of the scheduler, missed stages are not sent afterwards). Providers that are switched off and
 * Apple (no expiring secret) get none. In a federation only the hub sends (a standalone instance is its own hub);
 * the reminder state is local and never replicated, so a new hub may repeat the current stage once.
 */
class SecretReminder
{
    /** Local setting (never replicated): provider => {date, stage}. */
    public const REMINDERS_KEY = 'social_secret_reminders';

    /** Days before the expiry date at which a reminder is sent. */
    public const STAGES = [90, 60, 30, 14, 7, 3, 2, 1, 0];

    /** Stage after the date. */
    public const EXPIRED = 'expired';

    public function __construct(private readonly Topology $topology) {}

    /**
     * Send the due reminders; with $dryRun nothing is sent or stored.
     *
     * @param  (callable(string, array{date: string, days: int, level: string}, int|string|null, bool): void)|null  $report
     *                                                                                                                       per provider with a date: status, stage reached (null: none yet), whether it is due
     * @return int|null messages sent (dry run: due), null when this instance is not the hub
     */
    public function run(bool $dryRun = false, ?callable $report = null): ?int
    {
        if (! $this->topology->isHub()) {
            return null;
        }
        $state = LocalSetting::get(self::REMINDERS_KEY, []);
        $state = is_array($state) ? $state : [];
        $before = $state;
        $due = 0;
        foreach (SocialSettings::EXPIRING as $provider) {
            $status = SecretExpiry::status($provider);
            if ($status === null) {
                unset($state[$provider]);

                continue;
            }
            if (! SocialSettings::provider($provider)['enabled']) {
                continue;
            }
            $own = is_array($state[$provider] ?? null) && ($state[$provider]['date'] ?? null) === $status['date'] ? $state[$provider] : null;
            $stage = self::stage($status['days']);
            $isDue = $stage !== null && ($own === null || self::rank($stage) > self::rank($own['stage'] ?? null));
            if ($report !== null) {
                $report($provider, $status, $stage, $isDue);
            }
            if (! $isDue) {
                if ($own === null) {
                    unset($state[$provider]);
                }

                continue;
            }
            $due++;
            if (! $dryRun) {
                $this->send($provider, $status);
                $state[$provider] = ['date' => $status['date'], 'stage' => $stage];
            }
        }
        if (! $dryRun && $state !== $before) {
            LocalSetting::put(self::REMINDERS_KEY, $state);
        }

        return $due;
    }

    /** Latest stage reached with this many days left; null before the first one. */
    public static function stage(int $days): int|string|null
    {
        if ($days < 0) {
            return self::EXPIRED;
        }
        $reached = null;
        foreach (self::STAGES as $stage) {
            if ($days <= $stage) {
                $reached = $stage;
            }
        }

        return $reached;
    }

    /** Order of the stages: later stages rank higher; none ranks lowest. */
    private static function rank(mixed $stage): int
    {
        return match (true) {
            $stage === self::EXPIRED => PHP_INT_MAX,
            is_int($stage) => PHP_INT_MAX - 1 - $stage,
            default => -1,
        };
    }

    /**
     * One message for one provider: provider, date, days left (or expired), link to the provider card in the
     * settings. Webhook events social.secret_expiring and social.secret_expired.
     *
     * @param  array{date: string, days: int, level: string}  $status
     */
    public function send(string $provider, array $status): void
    {
        $locale = ApprovalSettings::locale();
        $expired = $status['days'] < 0;
        $url = ApprovalSettings::adminUrl().route('admin.settings', [], false).'#social-'.$provider;
        $date = SecretExpiry::format($status['date']);
        $name = SocialLogin::name($provider);
        $lines = [__('portal.social_secret.notify.provider', ['provider' => $name], $locale)];
        if ($expired) {
            $lines[] = __('portal.social_secret.notify.expired_on', ['date' => $date], $locale);
        } else {
            $lines[] = __('portal.social_secret.notify.valid_until', ['date' => $date], $locale);
            $lines[] = $status['days'] === 0 ? __('portal.social_secret.notify.today', [], $locale)
                : trans_choice('portal.social_secret.notify.days_left', $status['days'], ['days' => $status['days']], $locale);
        }
        $lines[] = __('portal.social_secret.notify.link', ['url' => $url], $locale);
        $title = __($expired ? 'portal.social_secret.notify.title_expired' : 'portal.social_secret.notify.title', [], $locale);
        $event = $expired ? 'social.secret_expired' : 'social.secret_expiring';
        $data = [
            'event' => $event,
            'provider' => $provider,
            'provider_name' => $name,
            'expires_at' => $status['date'],
            'days' => $status['days'],
            'url' => $url,
        ];
        foreach (Channel::query()->activeNotification()->get() as $channel) {
            try {
                $channel->driver()->announce($channel, $title, implode("\n", $lines), $data);
            } catch (Throwable $e) {
                Log::warning('Social secret reminder failed', ['provider' => $provider, 'channel' => $channel->id,
                    'driver' => $channel->driver, 'error' => Redact::secrets($e->getMessage())]);
            }
        }
        Log::info('Social secret reminder', ['provider' => $provider, 'event' => $event, 'expires_at' => $status['date'], 'days' => $status['days']]);
    }
}
