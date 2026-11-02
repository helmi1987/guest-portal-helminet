<?php

namespace App\Sms;

use App\Enums\GrantType;
use App\Jobs\SendSms;
use App\Models\Channel;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\OtpChallenge;
use App\Models\Site;
use App\Models\SmsMessage;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Services\GrantManager;
use App\Support\Redact;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SMS login (CONCEPT.md §7.2 B): create and send one-time passwords within the cost caps, verify
 * them and create the grant.
 */
class OtpService
{
    private const LOCK = 'sms-send';

    public function __construct(private GrantManager $grants) {}

    /** Name of the cap that sending one more SMS to this number, device or site would exceed, or null. */
    public function capReached(string $phoneE164, string $mac, string $siteId): ?string
    {
        $caps = SmsSettings::caps();
        $count = fn (int $hours, ?string $column = null, ?string $value = null) => SmsMessage::query()
            ->where('created_at', '>=', now()->subHours($hours))
            ->when($column, fn ($q) => $q->where($column, $value))->count();

        $checks = [
            'phone_hour' => fn () => $count(1, 'phone_e164', $phoneE164),
            'phone_day' => fn () => $count(24, 'phone_e164', $phoneE164),
            'mac_hour' => fn () => $count(1, 'mac', $mac),
            'mac_day' => fn () => $count(24, 'mac', $mac),
            'site_hour' => fn () => $count(1, 'site_id', $siteId),
            'site_day' => fn () => $count(24, 'site_id', $siteId),
            'global_day' => fn () => $count(24),
        ];
        foreach ($checks as $cap => $sent) {
            if ($sent() >= $caps[$cap]) {
                return $cap;
            }
        }

        return null;
    }

    /**
     * Guest, terms acceptance and challenge with a first SMS, stored in one transaction under the
     * send lock; null (nothing stored) when a cap is reached or the lock is busy. The caller
     * checked number and terms.
     *
     * @param  array{first_name: string, last_name: string}  $name
     */
    public function start(array $name, ?TermsVersion $terms, Site $site, string $mac, string $phoneE164, ?string $host = null): ?OtpChallenge
    {
        return $this->underLock(fn () => DB::transaction(function () use ($name, $terms, $site, $mac, $phoneE164, $host) {
            if (($cap = $this->capReached($phoneE164, $mac, $site->id)) !== null) {
                $this->logCap($cap, $site->id, $mac, $phoneE164);

                return null;
            }
            $guest = Guest::create([
                'first_name' => trim($name['first_name']),
                'last_name' => trim($name['last_name']),
                'phone_e164' => $phoneE164,
                'locale' => app()->getLocale(),
            ]);
            TermsAcceptance::create([
                'guest_id' => $guest->id,
                'terms_version_id' => $terms?->id,
                'site_id' => $site->id,
                'mac' => $mac,
                'accepted_at' => now(),
            ]);
            $challenge = OtpChallenge::create([
                'guest_id' => $guest->id,
                'site_id' => $site->id,
                'mac' => $mac,
                'phone_e164' => $phoneE164,
                'code_hash' => '',
                'expires_at' => now(),
            ]);
            $this->send($challenge, $guest->locale, $host);
            Log::info('SMS code requested', ['challenge' => $challenge->id, 'site' => $site->slug, 'mac' => $mac,
                'phone' => Redact::phone($phoneE164)]);

            return $challenge;
        }), busy: null);
    }

    /**
     * New code for an open challenge: 'sent', 'closed', 'wait' (too early), 'limit' (max. sends) or
     * 'cap' (cap reached or lock busy). Runs under the send lock, which verify() holds as well, so a
     * code cannot be replaced while it is being checked.
     */
    public function resend(OtpChallenge $challenge, ?string $host = null): string
    {
        return $this->underLock(fn () => DB::transaction(function () use ($challenge, $host) {
            $challenge->refresh();
            if (! $challenge->isOpen()) {
                return 'closed';
            }
            $config = config('portal.sms');
            if ($challenge->sends >= $config['max_sends']) {
                return 'limit';
            }
            if ($challenge->last_sent_at?->gt(now()->subSeconds($config['resend_after_s']))) {
                return 'wait';
            }
            if (($cap = $this->capReached($challenge->phone_e164, $challenge->mac, $challenge->site_id)) !== null) {
                $this->logCap($cap, $challenge->site_id, $challenge->mac, $challenge->phone_e164);

                return 'cap';
            }
            $this->send($challenge, $challenge->guest?->locale ?? app()->getLocale(), $host);

            return 'sent';
        }), busy: 'cap');
    }

    /**
     * Check a code. Returns the grant on success (time runs from now, restricted to the site),
     * otherwise null; every wrong code uses up one try. Runs under the send lock (see resend());
     * throws LockTimeoutException when the lock is busy.
     */
    public function verify(OtpChallenge $challenge, string $code): ?Grant
    {
        $grant = Cache::lock(self::LOCK, 15)->block(10, fn () => DB::transaction(function () use ($challenge, $code) {
            $fresh = OtpChallenge::query()->lockForUpdate()->find($challenge->id);
            if ($fresh === null || ! $fresh->isOpen()) {
                return null;
            }
            if (! hash_equals($fresh->code_hash, OtpChallenge::hashCode($fresh->id, $code))) {
                $fresh->increment('attempts');

                return null;
            }

            $guest = $fresh->guest;
            $grant = $this->grants->createTimedGrant(GrantType::Sms, $fresh->site, SmsSettings::durationS(),
                trim($guest->first_name.' '.$guest->last_name));
            $fresh->update(['verified_at' => now(), 'grant_id' => $grant->id]);
            // Per model (not a query-builder update): each acceptance gets a new version and outbox event.
            TermsAcceptance::query()->where('guest_id', $fresh->guest_id)->whereNull('grant_id')->get()
                ->each(fn (TermsAcceptance $acceptance) => $acceptance->update(['grant_id' => $grant->id]));

            return $grant;
        }));
        $challenge->refresh();
        if ($grant !== null) {
            Log::info('SMS code verified', ['challenge' => $challenge->id, 'grant' => $grant->id]);
        }

        return $grant;
    }

    /** New random code, stored hashed; the SMS is sent by the queue (payload encrypted). */
    private function send(OtpChallenge $challenge, string $locale, ?string $host): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = config('portal.sms.ttl_minutes');
        $challenge->update([
            'code_hash' => OtpChallenge::hashCode($challenge->id, $code),
            'sends' => $challenge->sends + 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
        ]);
        $message = SmsMessage::create([
            'otp_challenge_id' => $challenge->id,
            'site_id' => $challenge->site_id,
            'mac' => $challenge->mac,
            'phone_e164' => $challenge->phone_e164,
            'status' => SmsMessage::QUEUED,
        ]);
        $text = self::text($code, $minutes, $locale, $host);
        SendSms::dispatch($message->id, $text)->afterCommit();
    }

    /**
     * SMS body from the per-locale template (SmsSettings::text(), admin editable, CONCEPT.md §7.2 B).
     * The default uses the domain-bound one-time code format (WebKit origin-bound codes, WHATWG
     * sms-one-time-codes, Chrome WebOTP): code first, a blank line, then `@host #code` as last line so
     * iOS/Android offer the code for the page on that host. Without a valid host every template line
     * containing `:host` is omitted.
     */
    public static function text(string $code, int|string $minutes, string $locale, ?string $host): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", SmsSettings::text($locale));
        $host = strtolower(rtrim(trim((string) $host), '.'));
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (strlen($host) > 253 || ! preg_match('/^'.$label.'(?:\.'.$label.')*$/', $host)) {
            $host = '';
            $template = implode("\n", array_filter(explode("\n", $template), fn ($l) => ! str_contains($l, ':host')));
        }

        return trim(strtr($template, [':code' => $code, ':minutes' => (string) $minutes, ':host' => $host]));
    }

    /**
     * Cap checks and sends of all guests are serialised (like LoginThrottle) so parallel requests
     * cannot exceed a cap; writes are committed before the lock is released.
     */
    private function underLock(callable $callback, mixed $busy): mixed
    {
        try {
            return Cache::lock(self::LOCK, 15)->block(10, $callback);
        } catch (LockTimeoutException) {
            Log::warning('SMS send lock busy');

            return $busy;
        }
    }

    /** Site and instance caps protect the budget but can lock out all guests: tell the admin. */
    private function logCap(string $cap, string $siteId, string $mac, string $phoneE164): void
    {
        $context = ['cap' => $cap, 'site' => $siteId, 'mac' => $mac, 'phone' => Redact::phone($phoneE164)];
        str_starts_with($cap, 'site_') || $cap === 'global_day'
            ? Log::warning('SMS cost cap reached', $context)
            : Log::info('SMS cost cap reached', $context);
    }

    public static function enabled(): bool
    {
        return Channel::query()->activeSms()->exists();
    }
}
