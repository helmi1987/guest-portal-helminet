<?php

namespace App\Sms\Drivers;

use InvalidArgumentException;

/**
 * Registry of SMS drivers (CONCEPT.md §7.3); the order is the order of the admin's provider list.
 */
final class SmsDrivers
{
    /** @var array<string, class-string<SmsDriver>> */
    public const DRIVERS = [
        'twilio' => TwilioDriver::class,
        'seven' => SevenDriver::class,
        'clicksend' => ClickSendDriver::class,
        'clickatell' => ClickatellDriver::class,
        'smsapi' => SmsApiDriver::class,
        '46elks' => FortySixElksDriver::class,
        'cellsynt' => CellsyntDriver::class,
        'octopush' => OctopushDriver::class,
        'smspartner' => SmsPartnerDriver::class,
        'smsplanet' => SmsPlanetDriver::class,
        'promosms' => PromoSmsDriver::class,
        'serwersms' => SerwerSmsDriver::class,
        'smsc' => SmscDriver::class,
        'smsmanager' => SmsManagerDriver::class,
        'gtx' => GtxMessagingDriver::class,
        'aspsms' => AspsmsDriver::class,
        'ecall' => EcallDriver::class,
        'smseagle' => SmsEagleDriver::class,
        'freemobile' => FreeMobileDriver::class,
        'sms_webhook' => WebhookSmsDriver::class,
    ];

    /**
     * Drivers that cannot reach a guest's number (Free Mobile sends to the subscriber's own number): only
     * the admin test button uses them; they never send guest codes and do not enable the SMS login.
     */
    public const TEST_ONLY = ['freemobile'];

    public static function make(string $driver): SmsDriver
    {
        $class = self::DRIVERS[$driver] ?? throw new InvalidArgumentException("Unknown SMS driver [$driver].");

        return app($class);
    }
}
