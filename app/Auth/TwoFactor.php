<?php

namespace App\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Support\Hlc;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * TOTP two-factor login for admin UI users (CONCEPT.md §9, §12): RFC 6238 (30 s, 6 digits, SHA-1,
 * ±1 step), each step accepted once, 8 hashed single-use recovery codes.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public const REQUIRED_SETTING = 'require_2fa';

    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
        $this->google2fa->setWindow(1);
    }

    public static function required(): bool
    {
        return (bool) Setting::get(self::REQUIRED_SETTING, false);
    }

    /** Store a new, not yet confirmed secret (enrolment step 1). */
    public function begin(User $user): void
    {
        $user->forceFill([
            'totp_secret' => $this->google2fa->generateSecretKey(32),
            'totp_confirmed_at' => null,
            'totp_last_step' => null,
            'recovery_codes' => null,
        ])->save();
    }

    /** Confirm enrolment with a valid code; returns the plain recovery codes (shown once). */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->totp_secret === null || ! $this->verifyTotp($user, $code)) {
            return null;
        }
        $user->forceFill(['totp_confirmed_at' => now()])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_last_step' => null,
            'recovery_codes' => null,
        ])->save();
    }

    /** TOTP code or recovery code (consumed on use). */
    public function verify(User $user, string $code): bool
    {
        return $this->verifyTotp($user, $code) || $this->useRecoveryCode($user, $code);
    }

    public function verifyTotp(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($user->totp_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        try {
            $step = $this->google2fa->verifyKeyNewer($user->totp_secret, $code, (int) $user->totp_last_step);
        } catch (Throwable) {
            return false;
        }
        if ($step === false) {
            return false;
        }

        // Atomic: of two concurrent uses of the same step only one wins.
        $claimed = User::query()->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))
            ->update(['totp_last_step' => $step, 'version' => app(Hlc::class)->now()]);
        if ($claimed !== 1) {
            return false;
        }
        $user->forceFill(['totp_last_step' => $step])->syncOriginalAttribute('totp_last_step');

        return true;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = self::normaliseRecoveryCode($code);
        if ($code === '' || ! $user->hasTwoFactor()) {
            return false;
        }
        // Work on the stored value: a stale model cannot reuse a code another request consumed.
        $raw = User::query()->whereKey($user->getKey())->toBase()->value('recovery_codes');
        $hashes = $raw === null ? [] : (array) json_decode($raw, true);
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $remaining = array_values($hashes);
                // Atomic: only succeeds while the list is still the one the code was found in.
                $claimed = User::query()->whereKey($user->getKey())->where('recovery_codes', $raw)
                    ->update(['recovery_codes' => json_encode($remaining), 'version' => app(Hlc::class)->now()]);
                if ($claimed !== 1) {
                    return false;
                }
                $user->forceFill(['recovery_codes' => $remaining])->syncOriginalAttribute('recovery_codes');

                return true;
            }
        }

        return false;
    }

    /** @return list<string> plain codes, e.g. "k3x9q-7mfa2" */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $plain = Str::lower(Str::random(10));
            $codes[] = substr($plain, 0, 5).'-'.substr($plain, 5);
        }
        $user->forceFill(['recovery_codes' => array_map(fn ($c) => Hash::make(self::normaliseRecoveryCode($c)), $codes)])->save();

        return $codes;
    }

    public function otpauthUrl(User $user): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('portal.instance_name', 'Portal'), $user->email, (string) $user->totp_secret);
    }

    /** QR code of the otpauth URL as inline SVG markup. */
    public function qrSvg(User $user): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $writer->writeString($this->otpauthUrl($user)));
    }

    /** Current code (tests and diagnostics). */
    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    private static function normaliseRecoveryCode(string $code): string
    {
        return Str::lower((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
