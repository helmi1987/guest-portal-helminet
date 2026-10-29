<?php

namespace App\Console\Commands;

use App\Approval\ApprovalSettings;
use App\Approval\PollBackoff;
use App\Approval\TelegramPoller;
use App\Models\Channel;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Long-running Telegram poller (s6 service "telegram"). Idles unless this instance is the
 * Telegram owner and an enabled Telegram channel exists; exits after an hour to be restarted.
 * Failing channels (invalid token, network) back off exponentially (10 s up to 5 min, PollBackoff);
 * one warning per failed attempt (= per backoff step), recovery once at info level.
 */
class PollTelegram extends Command
{
    protected $signature = 'portal:telegram-poll {--once : Single poll without long-polling wait}';

    protected $description = 'Receive Telegram button presses for approval requests (long polling)';

    public function handle(TelegramPoller $poller, PollBackoff $backoff): int
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
            $waiting = [];
            foreach ($channels as $channel) {
                if (! $this->option('once') && ! $backoff->ready($channel->id)) {
                    $waiting[] = $channel->id;

                    continue;
                }
                try {
                    $poller->pollOnce($channel, $this->option('once') ? 0 : 25);
                    if (($failures = $backoff->succeeded($channel->id)) > 0) {
                        Log::info('Telegram polling recovered', ['channel' => $channel->id, 'failures' => $failures]);
                    }
                } catch (Throwable $e) {
                    // One attempt (and one warning) per backoff step: at most every 5 min while it keeps failing.
                    Log::warning('Telegram polling failed', ['channel' => $channel->id, 'retry_in_s' => $backoff->failed($channel->id),
                        'error' => Redact::secrets($e->getMessage())]);
                }
            }
            // Every channel backs off: sleep until the first one is due (polling itself blocks up to 25 s).
            if (! $this->option('once') && $waiting !== [] && count($waiting) === $channels->count()) {
                sleep(max(1, min(30, $backoff->wait($waiting))));
            }
        } while (! $this->option('once') && now()->lt($until));

        return self::SUCCESS;
    }
}
