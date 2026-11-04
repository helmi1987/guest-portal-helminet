<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * ASPSMS (Switzerland; CONCEPT.md §7.3), verified against docs on 2026-11-04: https://json.aspsms.com/
 * (SendSimpleTextSMS) and https://json.aspsms.com/ListAllStatusCodes (status codes, fetched live).
 *
 * POST https://json.aspsms.com/SendSimpleTextSMS, JSON {UserName (userkey), Password, Originator (number
 * unlocked in the account, or max. 11 characters), Recipients: ["+41...:<transaction ref>"], MessageText,
 * ForceGSM7bit: false} (encoding detected by ASPSMS). Answer {StatusCode, StatusInfo}; "1" = OK. The
 * answer carries no message id, so the driver sends its own transaction reference per recipient and
 * returns it. Codes 14/15 (invalid MCC/MNC of the number) reject the number; 3/8/9 (authorisation),
 * 5 (credit), 10/30 (originator), 24 (service unavailable) and others let the next provider take over.
 */
class AspsmsDriver extends HttpSmsDriver
{
    public const API = 'https://json.aspsms.com/SendSimpleTextSMS';

    public static function fields(): array
    {
        return [
            'userkey' => ['required' => true],
            'password' => ['secret' => true, 'required' => true],
            'sender' => ['required' => true],
        ];
    }

    protected function provider(): string
    {
        return 'ASPSMS';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $reference = (string) random_int(100000000, 999999999);
        $payload = [
            'UserName' => $channel->conf('userkey'),
            'Password' => $channel->conf('password'),
            'Originator' => $channel->conf('sender'),
            'Recipients' => [$phoneE164.':'.$reference],
            'MessageText' => $text,
            'ForceGSM7bit' => false,
        ];
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()->post(self::API, $payload));
        $this->failUnlessSuccessful($response);
        if ((string) $response->json('StatusCode') !== '1') {
            throw $this->rejected($response->json('StatusCode'), [14, 15]);
        }

        return $reference;
    }
}
