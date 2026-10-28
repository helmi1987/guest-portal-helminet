<?php

namespace App\Console\Commands;

use App\Approval\ApprovalSettings;
use App\Approval\TelegramPoller;
use App\Models\Channel;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Long-running Telegram poller (s6 service "telegram"). Idles unless this instance is the
 * Telegram owner and an enabled Telegram channel exists; exits after an hour to be restarted.
 */
class PollTelegram extends Command
{
    protected $signature = 'portal:telegram-poll {--once : Single poll without long-polling wait}';

    protected $description = 'Receive Telegram button presses for approval requests (long polling)';

    public function handle(TelegramPoller $poller): int
    {
        $until = now()->addHour();
        do {
            $channels = ApprovalSettings::isTelegramOwner()
                ? Channel::query()->activeNotification()->where('driver', 'telegram')->get()
                : collect();

            if ($channels->isEmpty() && ! $this->option('once')) {
                sleep(30);

                continue;
            }
            foreach ($channels as $channel) {
                try {
                    $poller->pollOnce($channel, $this->option('once') ? 0 : 25);
                } catch (Throwable $e) {
                    Log::warning('Telegram polling failed', ['channel' => $channel->id, 'error' => Redact::secrets($e->getMessage())]);
                    if (! $this->option('once')) {
                        sleep(10);
                    }
                }
            }
        } while (! $this->option('once') && now()->lt($until));

        return self::SUCCESS;
    }
}
