<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\SmsMessage;
use App\Support\Redact;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send one SMS via the first enabled SMS channel and record the provider's answer. The payload
 * contains the code, so the queued job is encrypted. Not retried (the guest can resend).
 */
class SendSms implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $messageId, public string $text) {}

    public function handle(): void
    {
        $message = SmsMessage::find($this->messageId);
        if ($message === null) {
            return;
        }
        $channel = Channel::query()->activeSms()->orderBy('name')->first();
        if ($channel === null) {
            $message->update(['status' => SmsMessage::FAILED, 'error' => 'No enabled SMS channel']);

            return;
        }

        try {
            $id = $channel->smsDriver()->send($channel, $message->phone_e164, $this->text);
            $message->update(['status' => SmsMessage::ACCEPTED, 'channel_id' => $channel->id, 'provider_id' => $id]);
            Log::info('SMS accepted', ['message' => $message->id, 'channel' => $channel->id, 'provider_id' => $id,
                'phone' => Redact::phone($message->phone_e164)]);
        } catch (Throwable $e) {
            $error = Redact::secrets($e->getMessage(), $channel->secretValues());
            $message->update(['status' => SmsMessage::FAILED, 'channel_id' => $channel->id, 'error' => mb_substr($error, 0, 255)]);
            Log::warning('SMS failed', ['message' => $message->id, 'channel' => $channel->id, 'driver' => $channel->driver,
                'phone' => Redact::phone($message->phone_e164), 'error' => $error]);
        }
    }
}
