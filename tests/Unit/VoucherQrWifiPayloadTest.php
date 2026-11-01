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

    public function test_open_network_has_no_password(): void
    {
        $this->assertSame('WIFI:S:Guest;T:nopass;;', VoucherQr::wifiPayload('Guest'));
        $this->assertSame('WIFI:S:Guest;T:nopass;;', VoucherQr::wifiPayload('Guest', ''));
    }

    public function test_hidden_network_appends_h_true(): void
    {
        $this->assertSame('WIFI:T:WPA;S:Guest;P:12345678;H:true;;', VoucherQr::wifiPayload('Guest', '12345678', true));
        $this->assertSame('WIFI:S:Guest;T:nopass;H:true;;', VoucherQr::wifiPayload('Guest', null, true));
        $this->assertSame('WIFI:T:WPA;S:Guest;P:12345678;;', VoucherQr::wifiPayload('Guest', '12345678', false));
    }
}
