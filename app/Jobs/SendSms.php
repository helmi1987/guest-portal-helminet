<?php

namespace App\Jobs;

use App\Models\OtpChallenge;
use App\Models\SmsMessage;
use App\Sms\SmsSender;
use App\Support\Redact;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Send one SMS via the active SMS provider, with failover to the backups (SmsSender, CONCEPT.md §7.2 B),
 * and record the result. The payload contains the code, so the queued job is encrypted. Not retried (the
 * guest can request a new code). When no provider delivers, the challenge is closed, so the guest's code
 * page shows the "sms_failed" error page instead of waiting for a code that never comes.
 */
class SendSms implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $messageId, public string $text) {}

    public function handle(SmsSender $sender): void
    {
        $message = SmsMessage::find($this->messageId);
        if ($message === null) {
            return;
        }

        $result = $sender->send($message->phone_e164, $this->text, ['message' => $message->id]);
        if ($result['status'] === 'accepted') {
            $message->update(['status' => SmsMessage::ACCEPTED, 'channel_id' => $result['channel']->id, 'provider_id' => $result['id']]);
            Log::info('SMS accepted', ['message' => $message->id, 'channel' => $result['channel']->id,
                'provider_id' => $result['id'], 'phone' => Redact::phone($message->phone_e164)]);

            return;
        }

        $message->update(['status' => SmsMessage::FAILED, 'channel_id' => $result['channel']?->id,
            'error' => mb_substr($result['error'], 0, 255)]);
        $challenge = $message->otp_challenge_id ? OtpChallenge::find($message->otp_challenge_id) : null;
        if ($challenge !== null && $challenge->isOpen()) {
            $challenge->update(['expires_at' => now()]);
        }
    }
}
