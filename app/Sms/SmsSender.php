<?php

namespace App\Sms;

use App\Approval\ApprovalSettings;
use App\Models\Channel;
use App\Support\Audit;
use App\Support\Redact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one SMS through the active provider with automatic failover to the backups (CONCEPT.md §7.2 B,
 * decision v0.26.0).
 *
 * Order: the enabled channels as listed (SmsProviders::chain()); channels in cooldown are skipped and tried
 * last, only when every other one failed. A failure (connection error, timeout, redirect, 5xx, 429, auth,
 * credit, any exception) puts the channel into cooldown for this instance and the next one is tried at once.
 * A rejection of the message itself (SmsFailure::$recipient: invalid or blocked number, HTTP 400/422
 * without a more specific code) stops: the backups would refuse it too, and the provider is not at fault.
 * Success through a backup while the active provider is down: one warning, audit `sms.failover` and one
 * admin notification per outage; the active provider's next success ends the outage (`sms.recovered`).
 */
class SmsSender
{
    /**
     * @return array{status: 'accepted', channel: Channel, id: ?string}|array{status: 'failed', channel: ?Channel, error: string}
     */
    public function send(string $phoneE164, string $text, array $context = []): array
    {
        $chain = SmsProviders::chain();
        if ($chain->isEmpty()) {
            return ['status' => 'failed', 'channel' => null, 'error' => 'No enabled SMS channel'];
        }
        $primary = $chain->first();
        [$ready, $cooling] = $chain->partition(fn (Channel $c) => SmsProviders::downSince($c) === null);
        $error = null;
        $last = null;

        foreach ($ready->concat($cooling) as $channel) {
            $last = $channel;
            try {
                $id = $channel->smsDriver()->send($channel, $phoneE164, $text);
            } catch (Throwable $e) {
                $error = mb_substr(Redact::secrets($e->getMessage(), $channel->secretValues()), 0, 255);
                $stop = $e instanceof SmsFailure && $e->recipient;
                Log::warning('SMS failed', [...$context, 'channel' => $channel->id, 'driver' => $channel->driver,
                    'phone' => Redact::phone($phoneE164), 'error' => $error, 'failover' => ! $stop]);
                if ($stop) {
                    break;
                }
                SmsProviders::markDown($channel);

                continue;
            }
            SmsProviders::clearDown($channel);
            $channel->is($primary) ? $this->recovered($primary) : $this->failedOver($primary, $channel);

            return ['status' => 'accepted', 'channel' => $channel, 'id' => $id];
        }

        return ['status' => 'failed', 'channel' => $last, 'error' => (string) $error];
    }

    /** A backup delivered while the active provider is down: report once per outage. */
    private function failedOver(Channel $primary, Channel $used): void
    {
        $downSince = SmsProviders::downSince($primary);
        $outage = SmsProviders::outage();
        if ($downSince === null && $outage === null) {
            return; // the primary was not tried (should not happen: chain order) or recovered meanwhile
        }
        Cache::put(SmsProviders::OUTAGE, ['primary' => $primary->id, 'used' => $used->id,
            'failed_at' => $downSince ?? ($outage['failed_at'] ?? now()->getTimestamp())], now()->addDays(7));
        if ($outage !== null && $outage['primary'] === $primary->id) {
            return;
        }
        $data = ['primary' => $primary->name, 'used' => $used->name];
        Log::warning('SMS failover', ['primary' => $primary->id, 'used' => $used->id]);
        Audit::log('sms.failover', $primary, $data, actor: 'system');
        $this->notifyAdmins(__('portal.channels.failover_notice', $data, ApprovalSettings::locale()));
    }

    /** The active provider delivered again: end the outage. */
    private function recovered(Channel $primary): void
    {
        $outage = SmsProviders::outage();
        if ($outage === null) {
            return;
        }
        Cache::forget(SmsProviders::OUTAGE);
        Log::info('SMS provider recovered', ['primary' => $primary->id]);
        Audit::log('sms.recovered', $primary, ['primary' => $primary->name], actor: 'system');
    }

    /** One message to every enabled notification channel (their test() sends plain text); errors are logged. */
    private function notifyAdmins(string $text): void
    {
        foreach (Channel::query()->activeNotification()->get() as $channel) {
            try {
                $channel->driver()->test($channel, $text);
            } catch (Throwable $e) {
                Log::warning('SMS failover notification failed', ['channel' => $channel->id,
                    'error' => Redact::secrets($e->getMessage(), $channel->secretValues())]);
            }
        }
    }
}
