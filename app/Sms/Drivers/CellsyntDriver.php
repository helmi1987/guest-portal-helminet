<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * Cellsynt (CONCEPT.md §7.3), verified against docs on 2026-11-04 (partly):
 * https://www.cellsynt.com/en/sms/api-integration
 *
 * HTTP GET or POST to https://se-1.cellsynt.net/sms.php with username, password, destination, text,
 * originatortype (alpha / numeric), originator; answer "OK: <tracking id>" or "Error: <message>".
 * Unverified (detailed parameter list only in Cellsynt's PDF manual, same as Uptime Kuma's driver):
 * destination as 00 + international number, charset=UTF-8, type=unicode for non-GSM text, allowconcat
 * (max. parts). Error messages are free text: every "Error:" answer counts as provider failure.
 */
class CellsyntDriver extends HttpSmsDriver
{
    public const API = 'https://se-1.cellsynt.net/sms.php';

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
        return 'Cellsynt';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $sender = (string) $channel->conf('sender');
        $form = [
            'username' => $channel->conf('username'),
            'password' => $channel->conf('password'),
            'destination' => '00'.self::digits($phoneE164),
            'text' => $text,
            'charset' => 'UTF-8',
            'type' => self::isGsm7($text) ? 'text' : 'unicode',
            'allowconcat' => 6,
            'originatortype' => preg_match('/^\+?\d+$/', $sender) ? 'numeric' : 'alpha',
            'originator' => ltrim($sender, '+'),
        ];
        $response = $this->request($channel, fn ($http) => $http->asForm()->post(self::API, $form));
        $this->failUnlessSuccessful($response);
        $body = trim($response->body());
        if (! str_starts_with($body, 'OK:')) {
            throw $this->rejected('error');
        }

        return self::id(trim(substr($body, 3)));
    }
}
