<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * Free Mobile notification SMS (France; CONCEPT.md §7.3). Free publishes no API page; the behaviour is
 * documented in the subscriber area and in community sources, checked on 2026-11-04 (unverified against an
 * official page): https://gist.github.com/VivienGiraud/ce457b1c67db904ee344f55b46d14721 and Uptime Kuma's
 * driver (https://github.com/louislam/uptime-kuma/blob/master/server/notification-providers/freemobile.js).
 *
 * POST https://smsapi.free-mobile.fr/sendmsg with JSON {user, pass, msg}; HTTP 200 sent, 400 parameter
 * missing, 402 too many SMS, 403 service not enabled or wrong login/key, 500 server error.
 * Limitation: the SMS always goes to the Free Mobile subscriber's own number, the recipient is ignored.
 * Only usable for an admin's own test, never for guest logins. No message id.
 */
class FreeMobileDriver extends HttpSmsDriver
{
    public const API = 'https://smsapi.free-mobile.fr/sendmsg';

    public static function fields(): array
    {
        return [
            'login' => ['required' => true],
            'password' => ['secret' => true, 'required' => true],
        ];
    }

    protected function provider(): string
    {
        return 'Free Mobile';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $response = $this->request($channel, fn ($http) => $http->asJson()
            ->post(self::API, ['user' => $channel->conf('login'), 'pass' => $channel->conf('password'), 'msg' => $text]));
        // A 400 here means a missing parameter, not a bad number (the number is not sent at all).
        $this->failUnlessSuccessful($response, $response->status(), []);

        return null;
    }
}
