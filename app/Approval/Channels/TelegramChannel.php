<?php

namespace App\Approval\Channels;

use App\Approval\ApprovalMessage;
use App\Approval\ApprovalSettings;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Support\Redact;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Telegram bot posting to one group with inline buttons (CONCEPT.md §7.2 C). Button presses are
 * received by the Telegram owner via long polling (TelegramPoller).
 */
class TelegramChannel implements NotificationChannel
{
    public const API = 'https://api.telegram.org';

    public static function fields(): array
    {
        return [
            'bot_token' => ['secret' => true, 'required' => true],
            'chat_id' => ['required' => true],
            'allowed_user_ids' => [],
        ];
    }

    public function requested(ApprovalRequest $request, Channel $channel): ?array
    {
        $buttons = [];
        foreach (array_keys(ApprovalSettings::durations()) as $choice) {
            $buttons[] = ['text' => ApprovalMessage::choiceLabel($choice), 'callback_data' => self::callbackData($request, $choice)];
        }
        $keyboard = [$buttons, [['text' => ApprovalMessage::choiceLabel('deny'), 'callback_data' => self::callbackData($request, 'deny')]]];

        $message = $this->call($channel, 'sendMessage', [
            'chat_id' => $channel->conf('chat_id'),
            'text' => $this->text($request),
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ])->json('result');

        return ['chat_id' => $message['chat']['id'] ?? $channel->conf('chat_id'), 'message_id' => $message['message_id'] ?? null];
    }

    public function decided(ApprovalRequest $request, Channel $channel, ?array $meta): void
    {
        if (empty($meta['message_id'])) {
            return;
        }
        // Without reply_markup the inline keyboard is removed.
        $this->call($channel, 'editMessageText', [
            'chat_id' => $meta['chat_id'],
            'message_id' => $meta['message_id'],
            'text' => $this->text($request)."\n\n".ApprovalMessage::decision($request),
        ]);
    }

    /** Callback data (max 64 bytes): request id, origin instance (federation), choice. */
    public static function callbackData(ApprovalRequest $request, string $choice): string
    {
        return implode(':', ['a', $request->id, $request->origin_instance_id, $choice]);
    }

    /** Errors are rethrown without the bot token (it is part of the URL). */
    public function call(Channel $channel, string $method, array $params, int $timeout = 10): Response
    {
        try {
            return Http::asJson()->timeout($timeout)
                ->post(self::API.'/bot'.$channel->conf('bot_token').'/'.$method, $params)
                ->throw();
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram '.$method.' failed: '.str_replace((string) $channel->conf('bot_token'), '***',
                Redact::secrets($e->getMessage())));
        }
    }

    private function text(ApprovalRequest $request): string
    {
        return ApprovalMessage::title()."\n".implode("\n", ApprovalMessage::lines($request));
    }
}
