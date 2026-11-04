<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * 46elks (CONCEPT.md §7.3), verified against docs on 2026-11-04: https://46elks.com/docs/send-sms
 *
 * POST https://api.46elks.com/a1/sms, HTTP Basic with API username + API password, form fields from (text
 * sender, max. 11 characters A-Z a-z 0-9 starting with a letter, or an E.164 number), to (E.164 with "+"),
 * message (UTF-8). Answer JSON with `id` and `status`. The error body is not documented (unverified):
 * errors are mapped by HTTP status only.
 */
class FortySixElksDriver extends HttpSmsDriver
{
    public const API = 'https://api.46elks.com/a1/sms';

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
        return '46elks';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $response = $this->request($channel, fn ($http) => $http->asForm()->acceptJson()
            ->withBasicAuth($channel->conf('username'), $channel->conf('password'))
            ->post(self::API, ['from' => $channel->conf('sender'), 'to' => $phoneE164, 'message' => $text]));
        $this->failUnlessSuccessful($response);
        if ($response->json('status') === 'failed') {
            throw $this->rejected('failed');
        }

        return self::id($response->json('id'));
    }
}
