<?php

namespace App\Console\Commands;

use App\Auth\Social\SecretExpiry;
use App\Auth\Social\SecretReminder;
use App\Auth\Social\SocialLogin;
use Illuminate\Console\Command;

/**
 * Reminders before social login provider secrets expire (daily, decision 2026-11-05, v0.31.0): one message per
 * provider and stage over the enabled notification channels, sent by the hub only.
 */
class SocialSecretReminders extends Command
{
    protected $signature = 'portal:secret-reminders {--dry-run : Show what would be sent, send and store nothing}';

    protected $description = 'Remind the admins over the notification channels before a social login secret expires';

    public function handle(SecretReminder $reminder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = $reminder->run($dryRun, function (string $provider, array $status, int|string|null $stage, bool $due) use ($dryRun) {
            $when = $status['days'] < 0 ? 'expired on '.SecretExpiry::format($status['date'])
                : 'valid until '.SecretExpiry::format($status['date']).' ('.$status['days'].' days left)';
            $what = match (true) {
                ! $due => $stage === null ? 'no reminder yet' : 'stage '.$stage.' already sent',
                $dryRun => 'would send stage '.$stage,
                default => 'sent stage '.$stage,
            };
            $this->line(SocialLogin::name($provider).": secret {$when}, {$what}");
        });
        if ($count === null) {
            $this->line('Not the federation hub: the hub sends the reminders.');
        }

        return self::SUCCESS;
    }
}
