<?php

namespace Tests\Unit;

use App\Services\VoucherQr;
use PHPUnit\Framework\TestCase;

class VoucherQrWifiPayloadTest extends TestCase
{
    public function test_digits_only_and_hex_only_values_are_not_quoted(): void
    {
        $this->assertSame('WIFI:T:WPA;S:Guest;P:12345678;;', VoucherQr::wifiPayload('Guest', '12345678'));
        $this->assertSame('WIFI:T:WPA;S:CAFE01;P:DEADBEEF;;', VoucherQr::wifiPayload('CAFE01', 'DEADBEEF'));
    }

    public function test_special_characters_are_escaped(): void
    {
        $this->assertSame('WIFI:T:WPA;S:a\;b;P:p\\:w\\,d\\"\\\;;', VoucherQr::wifiPayload('a;b', 'p:w,d"\\'));
    }
}
