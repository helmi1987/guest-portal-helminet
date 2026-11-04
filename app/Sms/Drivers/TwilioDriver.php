<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * Twilio Programmable Messaging (CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://www.twilio.com/docs/messaging/api/message-resource (Create a Message),
 * https://www.twilio.com/docs/api/errors/21211 (invalid "To").
 *
 * POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json, form fields To (E.164 with
 * "+"), Body and From or MessagingServiceSid; HTTP Basic with Account SID + Auth Token, or API key SID +
 * API key secret. Answer 201 with `sid` (SM...). Unicode is detected by Twilio. Errors: JSON {code,
 * message, status}; the body format and the use of HTTP 400 for 21211 are not shown on the pages above
 * (unverified, Twilio's general error format). Codes 21211 (invalid To), 21614 (not a mobile number) and
 * 21610 (recipient unsubscribed) reject the number.
 */
class TwilioDriver extends HttpSmsDriver
{
    public const API = 'https://api.twilio.com/2010-04-01/Accounts/';

    private const RECIPIENT_CODES = [21211, 21614, 21610];

    public static function fields(): array
    {
        return [
            'account_sid' => ['required' => true],
            'auth_token' => ['secret' => true, 'required' => true],
            'api_key_sid' => [],
            'from' => [],
            'messaging_service_sid' => [],
        ];
    }

    protected function provider(): string
    {
        return 'Twilio';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $form = ['To' => $phoneE164, 'Body' => $text];
        if ($channel->conf('messaging_service_sid')) {
            $form['MessagingServiceSid'] = $channel->conf('messaging_service_sid');
        } else {
            $form['From'] = $channel->conf('from');
        }
        $account = $channel->conf('account_sid');
        $response = $this->request($channel, fn ($http) => $http->asForm()->acceptJson()
            ->withBasicAuth($channel->conf('api_key_sid', $account), $channel->conf('auth_token'))
            ->post(self::API.rawurlencode($account).'/Messages.json', $form));
        $this->failUnlessSuccessful($response, $response->json('code'), self::RECIPIENT_CODES);

        return self::id($response->json('sid'));
    }
}
