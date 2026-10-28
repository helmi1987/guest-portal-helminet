<?php

namespace App\Approval;

use App\Approval\Channels\TelegramChannel;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\LocalSetting;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\Log;

/**
 * Long polling of Telegram updates (getUpdates) for button presses, run by the Telegram owner only
 * (CONCEPT.md §7.2 C, §10.5). A press is accepted only from the configured group and, if an
 * allowlist is set, from a listed user id.
 */
class TelegramPoller
{
    public function __construct(private TelegramChannel $telegram, private ApprovalService $approvals) {}

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
        $durations = ApprovalSettings::durations();
        $request = $tag === 'a' ? ApprovalRequest::find($requestId) : null;
        // Requests of other instances: forwarding to the origin follows with federation (M7).
        if ($request === null || $origin !== app(LocalInstance::class)->id() || ($choice !== 'deny' && ! isset($durations[$choice]))) {
            $this->answer($channel, $callback, 'unknown');

            return;
        }

        $by = 'Telegram: '.trim(data_get($callback, 'from.first_name', '').' '.data_get($callback, 'from.last_name', ''))
            .(data_get($callback, 'from.username') ? ' (@'.data_get($callback, 'from.username').')' : '');
        $done = $this->approvals->decide($request, $choice === 'deny' ? null : $durations[$choice], $by, 'telegram');
        $this->answer($channel, $callback, $done ? 'done' : 'closed');
    }

    private function answer(Channel $channel, array $callback, string $key): void
    {
        $this->telegram->call($channel, 'answerCallbackQuery', [
            'callback_query_id' => $callback['id'] ?? null,
            'text' => __('portal.approvals.telegram.'.$key, [], ApprovalSettings::locale()),
        ]);
    }
}
