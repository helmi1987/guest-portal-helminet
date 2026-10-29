<?php

namespace App\Sms;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalises guest input to E.164 (default region CH for national numbers) and checks it against
 * the country allowlist.
 */
final class PhoneNumber
{
    public const DEFAULT_REGION = 'CH';

    /**
     * @param  list<string>  $countries
     * @return array{0: ?string, 1: ?string} [E.164 number, null] or [null, error key: invalid|country]
     */
    public static function normalise(string $input, array $countries): array
    {
        $util = PhoneNumberUtil::getInstance();
        $input = preg_replace('/^00/', '+', trim($input));
        try {
            $number = $util->parse($input, self::DEFAULT_REGION);
        } catch (NumberParseException) {
            return [null, 'invalid'];
        }
        if (! $util->isValidNumber($number)) {
            return [null, 'invalid'];
        }
        $type = $util->getNumberType($number);
        if (! in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
            return [null, 'invalid'];
        }
        if (! in_array($util->getRegionCodeForNumber($number), $countries, true)) {
            return [null, 'country'];
        }

        return [$util->format($number, PhoneNumberFormat::E164), null];
    }
}
