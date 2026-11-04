<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SMSC (smsc.kz; CONCEPT.md §7.3), verified against docs on 2026-11-04: https://smsc.kz/api/http/send/sms/
 *
 * GET or POST https://smsc.kz/sys/send.php (other SMSC regions via field api_url), form fields login, psw,
 * phones (international digits), mes, sender (optional), charset=utf-8, fmt=3 (JSON). Success {id, cnt};
 * error {error, error_code}. Codes 7 (invalid number format) and 8 (cannot be delivered to the number)
 * reject the number; 1 (parameters), 2 (login), 3 (credit), 4 (IP blocked), 6 (message forbidden), 9 (too
 * many requests) let the next provider take over. Sent as POST so text and password stay out of URLs.
 */
class SmscDriver extends HttpSmsDriver
{
    public const API = 'https://smsc.kz/sys/send.php';

    public static function fields(): array
    {
        return [
            'login' => ['required' => true],
            'password' => ['secret' => true, 'required' => true],
            'from' => [],
            'api_url' => [],
        ];
    }

    protected function provider(): string
    {
        return 'SMSC';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $form = array_filter(['login' => $channel->conf('login'), 'psw' => $channel->conf('password'),
            'phones' => self::digits($phoneE164), 'mes' => $text, 'sender' => $channel->conf('from'),
            'charset' => 'utf-8', 'fmt' => 3]);
        $response = $this->request($channel, fn ($http) => $http->asForm()->acceptJson()
            ->post($channel->conf('api_url', self::API), $form));
        $this->failUnlessSuccessful($response);
        if ($response->json('id') === null) {
            throw $this->rejected($response->json('error_code'), [7, 8]);
        }

        return self::id($response->json('id'));
    }
}
