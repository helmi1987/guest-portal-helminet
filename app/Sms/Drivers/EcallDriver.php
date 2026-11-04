<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * eCall (Switzerland) REST API v2 (CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://developer.ecall-messaging.com/api-references/rest-api/message.md (OpenAPI 2.0 of
 * https://rest.ecall.ch).
 *
 * POST https://rest.ecall.ch/api/v2/message, HTTP Basic with the account or an API sub-user (REST
 * interface enabled under Interfaces > Overview), JSON {channel: "Sms", from (16 digits or 11
 * alphanumeric characters), to (international, +41... or 0041...), content: {type: "Text", text (UTF-8)}}.
 * Answer 200 {messageId, timestamp}; errors 400/401/500 with {errorCode, errorMessage, errorDetails}.
 * Codes TooManyMessagesSameReceiver(AndContent) concern this number only (no failover); InvalidContent,
 * InsufficientPoints, InvalidSenderAccount, AdrCountryNotWhitelisted and others let the next provider take
 * over.
 */
class EcallDriver extends HttpSmsDriver
{
    public const API = 'https://rest.ecall.ch/api/v2/message';

    private const RECIPIENT_CODES = ['TooManyMessagesSameReceiver', 'TooManyMessagesSameReceiverAndContent'];

    public static function fields(): array
    {
        return [
            'username' => ['required' => true],
            'password' => ['secret' => true, 'required' => true],
            'sender' => ['required' => true],
        ];
    }

    protected function provider(): string
    {
        return 'eCall';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = ['channel' => 'Sms', 'from' => $channel->conf('sender'), 'to' => $phoneE164,
            'content' => ['type' => 'Text', 'text' => $text]];
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()
            ->withBasicAuth($channel->conf('username'), $channel->conf('password'))->post(self::API, $payload));
        $this->failUnlessSuccessful($response, $response->json('errorCode'), self::RECIPIENT_CODES);

        return self::id($response->json('messageId'));
    }
}
