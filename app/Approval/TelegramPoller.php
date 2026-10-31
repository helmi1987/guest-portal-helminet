<?php

namespace App\Approval;

use App\Approval\Channels\TelegramChannel;
use App\Federation\RemoteApprovals;
use App\Models\Channel;
use App\Models\LocalSetting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Long polling of Telegram updates (getUpdates) for button presses, run by the Telegram owner only
 * (CONCEPT.md §7.2 C, §10.5). A press is accepted only from the configured group and, if an
 * allowlist is set, from a listed user id. Presses for requests of other instances are forwarded to
 * the instance where the guest waits (RemoteApprovals).
 */
class TelegramPoller
{
    public function __construct(private TelegramChannel $telegram, private RemoteApprovals $remote) {}

    /** One getUpdates call; returns the number of updates processed. */
    public function pollOnce(Channel $channel, int $timeout): int
    {
        $offsetKey = 'telegram_offset.'.$channel->id;
        $updates = $this->telegram->call($channel, 'getUpdates', [
            'offset' => LocalSetting::get($offsetKey),
            'timeout' => $timeout,
            'allowed_updates' => ['callback_query'],
        ], $timeout + 10)->json('result') ?? [];

        foreach ($updates as $update) {
            // Advance first: a failing update must not block the queue forever.
            LocalSetting::put($offsetKey, $update['update_id'] + 1);
            if (isset($update['callback_query'])) {
                $this->handleCallback($channel, $update['callback_query']);
            }
        }

        return count($updates);
    }

    public function handleCallback(Channel $channel, array $callback): void
    {
        $chatId = (string) data_get($callback, 'message.chat.id');
        $userId = (string) data_get($callback, 'from.id');
        $allowed = array_filter(array_map('trim', explode(',', (string) $channel->conf('allowed_user_ids', ''))));

        if ($chatId !== (string) $channel->conf('chat_id') || ($allowed !== [] && ! in_array($userId, $allowed, true))) {
            Log::warning('Telegram callback rejected', ['channel' => $channel->id, 'chat' => $chatId, 'user' => $userId]);
            $this->answer($channel, $callback, 'not_allowed');

            return;
        }

        [$tag, $requestId, $origin, $choice] = array_pad(explode(':', (string) ($callback['data'] ?? '')), 4, null);
        if ($tag !== 'a' || ! is_string($requestId) || ! is_string($origin) || ! is_string($choice)) {
            $this->answer($channel, $callback, 'unknown');

            return;
        }

        $by = 'Telegram: '.trim(data_get($callback, 'from.first_name', '').' '.data_get($callback, 'from.last_name', ''))
            .(data_get($callback, 'from.username') ? ' (@'.data_get($callback, 'from.username').')' : '');
        // Requests of other instances are decided at their origin (forwarded, through the hub if needed).
        try {
            $status = $this->remote->decide($origin, $requestId, $choice, $by);
        } catch (Throwable $e) {
            Log::warning('Telegram decision could not be forwarded', ['request' => $requestId, 'origin' => $origin,
                'error' => $e->getMessage()]);
            $status = 'unreachable';
        }
        $this->answer($channel, $callback, $status);
    }

    private function answer(Channel $channel, array $callback, string $key): void
    {
        $this->telegram->call($channel, 'answerCallbackQuery', [
            'callback_query_id' => $callback['id'] ?? null,
            'text' => __('portal.approvals.telegram.'.$key, [], ApprovalSettings::locale()),
        ]);
    }
}
