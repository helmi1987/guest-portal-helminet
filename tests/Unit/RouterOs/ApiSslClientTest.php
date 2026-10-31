<?php

namespace Tests\Unit\RouterOs;

use App\Nas\RouterOs\ApiSentence;
use App\Nas\RouterOs\ApiSslClient;
use App\Nas\RouterOs\Menus;
use App\Nas\RouterOs\RouterOsException;
use Tests\TestCase;

/**
 * Minimal RouterOS API-SSL client (CONCEPT.md §9, decision 2026-10-31): wire format, login, print
 * only, pin before credentials, error kinds.
 */
class ApiSslClientTest extends TestCase
{
    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private function client(FakeApiConnection $c, string $password = 'test-pass'): ApiSslClient
    {
        return new ApiSslClient(FakeApiConnection::connector($c), '10.0.0.1', 8729, 'portal-ro', $password, self::PIN, 1, 1);
    }

    public function test_length_prefixes_round_trip(): void
    {
        foreach ([1, 0x7F, 0x80, 0x3FFF, 0x4000, 0x10000] as $n) {
            $word = str_repeat('a', $n);
            $c = new FakeApiConnection(fn () => [], null);
            $encoded = ApiSentence::encode([$word]);
            $prefix = strlen($encoded) - $n - 1;
            $this->assertSame($n < 0x80 ? 1 : ($n < 0x4000 ? 2 : ($n < 0x200000 ? 3 : 4)), $prefix, "length $n");
            $c->write($encoded);
            $this->assertSame([$word], $c->received[0]);
        }
        $this->assertSame("\x80\x80", ApiSentence::length(0x80));
        $this->assertSame("\xC0\x40\x00", ApiSentence::length(0x4000));
        $this->assertSame("\xDF\xFF\xFF", ApiSentence::length(0x1FFFFF));
        $this->assertSame("\xE0\x20\x00\x00", ApiSentence::length(0x200000));
        $this->assertSame("\xF0\x10\x00\x00\x00", ApiSentence::length(0x10000000));
    }

    public function test_response_caps_and_deadline(): void
    {
        $menus = ['/ip pool' => array_map(fn ($i) => ['name' => 'p'.$i], range(1, 40))];
        $mk = fn () => new FakeApiConnection(FakeApiConnection::router('portal-ro', 'test-pass', $menus), self::PIN);
        $kind = function (callable $f): string {
            try {
                $f();
            } catch (RouterOsException $e) {
                return $e->kind.': '.$e->getMessage();
            }

            return 'none';
        };
        // Too many sentences (max_rows 5 + slack)
        $c = new ApiSslClient(FakeApiConnection::connector($mk()), '10.0.0.1', 8729, 'portal-ro', 'test-pass', self::PIN, 1, 1, 5);
        $this->assertStringContainsString('protocol: answer has too many sentences', $kind(fn () => $c->print('/ip pool', ['name'])));
        // Byte budget
        $c = new ApiSslClient(FakeApiConnection::connector($mk()), '10.0.0.1', 8729, 'portal-ro', 'test-pass', self::PIN, 1, 1, 2000, null, 100);
        $this->assertStringContainsString('protocol: answer too large', $kind(fn () => $c->print('/ip pool', ['name'])));
        // Word cap
        $c = new ApiSslClient(FakeApiConnection::connector($mk()), '10.0.0.1', 8729, 'portal-ro', 'test-pass', self::PIN, 1, 1, 2000, null, 8388608, 5);
        $this->assertStringContainsString('protocol: word too long', $kind(fn () => $c->print('/ip pool', ['name'])));
        // Deadline already passed
        $c = new ApiSslClient(FakeApiConnection::connector($mk()), '10.0.0.1', 8729, 'portal-ro', 'test-pass', self::PIN, 1, 1, 2000, microtime(true) - 1);
        $this->assertStringContainsString('timeout:', $kind(fn () => $c->print('/ip pool', ['name'])));
        // Empty sentences count toward the byte budget (1 each)
        $empty = new FakeApiConnection(fn (array $s) => ($s[0] ?? '') === '/login' ? [['!done']] : array_fill(0, 50, []), self::PIN);
        $c = new ApiSslClient(FakeApiConnection::connector($empty), '10.0.0.1', 8729, 'portal-ro', 'test-pass', self::PIN, 1, 1, 2000, null, 10);
        $this->assertStringContainsString('protocol: answer too large', $kind(fn () => $c->print('/ip pool', ['name'])));
    }

    public function test_login_then_print_with_proplist_and_or_query(): void
    {
        $fake = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'test-pass', [
            '/ip firewall address-list' => [['list' => 'PORTAL', 'address' => '10.0.0.10', 'comment' => 'guest-portal:fw:addr-portal', 'extra' => 'x']],
            '/caps-man manager' => [],
        ]), self::PIN);
        $client = $this->client($fake);
        $rows = $client->print('/ip firewall address-list', ['.id', 'list', 'address', 'comment'], ['list=PORTAL', 'list=RFC1918']);
        $this->assertSame([['.id' => '*1', 'list' => 'PORTAL', 'address' => '10.0.0.10', 'comment' => 'guest-portal:fw:addr-portal']], $rows);
        $this->assertSame([], $client->print('/caps-man manager', ['enabled']));
        $client->close();

        $this->assertSame(['/login', '=name=portal-ro', '=password=test-pass'], $fake->received[0]);
        $this->assertSame(['/ip/firewall/address-list/print', '=.proplist=.id,list,address,comment', '?list=PORTAL', '?list=RFC1918', '?#|'], $fake->received[1]);
        $this->assertSame('/caps-man/manager/print', $fake->received[2][0]);
        $this->assertTrue($fake->closed);
        // Print only: every command after the login is a print
        foreach (array_slice($fake->received, 1) as $sentence) {
            $this->assertStringEndsWith('/print', $sentence[0]);
        }
    }

    public function test_pin_mismatch_sends_nothing(): void
    {
        $fake = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'test-pass', ['/interface' => []]),
            'sha256//BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=');
        try {
            $this->client($fake)->print('/interface', ['name']);
            $this->fail('expected a TLS error');
        } catch (RouterOsException $e) {
            $this->assertSame('tls', $e->kind);
        }
        $this->assertSame([], $fake->received);
        $this->assertTrue($fake->closed);

        $none = new FakeApiConnection(fn () => [], null);
        $this->expectExceptionObject(new RouterOsException('tls', 'the router certificate does not match the pinned key'));
        $this->client($none)->print('/interface', ['name']);
    }

    public function test_error_kinds(): void
    {
        $fake = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'test-pass', []), self::PIN);
        $kind = function (callable $fn) {
            try {
                $fn();
            } catch (RouterOsException $e) {
                return $e->kind;
            }

            return null;
        };
        $this->assertSame('auth', $kind(fn () => $this->client($fake, 'wrong')->print('/interface', ['name'])));
        $this->assertStringNotContainsString('wrong', json_encode((new RouterOsException('auth', 'login refused'))->toArray()));

        $fake = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'test-pass', []), self::PIN);
        $this->assertSame('menu', $kind(fn () => $this->client($fake)->print('/caps-man manager', ['enabled'])));

        $denied = new FakeApiConnection(fn (array $s) => $s[0] === '/login' ? [['!done']]
            : [['!trap', '=message=not enough permissions (9)'], ['!done']], self::PIN);
        $this->assertSame('denied', $kind(fn () => $this->client($denied)->print('/user', ['name'])));

        $fatal = new FakeApiConnection(fn (array $s) => $s[0] === '/login' ? [['!done']] : [['!fatal', 'session terminated']], self::PIN);
        $this->assertSame('protocol', $kind(fn () => $this->client($fatal)->print('/user', ['name'])));

        $silent = new FakeApiConnection(fn (array $s) => $s[0] === '/login' ? [['!done']] : [], self::PIN);
        $this->assertSame('timeout', $kind(fn () => $this->client($silent)->print('/user', ['name'])));
    }

    public function test_only_whitelisted_menus_and_safe_properties(): void
    {
        $fake = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'test-pass', ['/interface' => []]), self::PIN);
        $client = $this->client($fake);
        $cases = [
            ['/system reboot', ['name'], []], ['/interface/print', ['name'], []], ['/interface set', ['name'], []],
            ['/file', ['name'], []], ['/interface', [], []], ['/radius', ['secret'], []], ['/interface wifi security', ['passphrase'], []],
            ['/system script', ['source'], []], ['/user', ['password'], []], ['/interface', ['name=x'], []],
            ['/interface', ['name'], ['name=a b']], ['/interface', ['name'], ['secret=x']], ['/interface', ['name'], ['=x']],
            ['/interface', ['name'], ["name=a\n/system/reboot"]],
        ];
        foreach ($cases as [$menu, $props, $where]) {
            try {
                $client->print($menu, $props, $where);
                $this->fail("accepted: {$menu}");
            } catch (RouterOsException $e) {
                $this->assertSame('config', $e->kind, $menu);
            }
        }
        $this->assertSame([], $fake->received, 'nothing is sent for a refused call');
        foreach (Menus::ALLOWED as $menu) {
            $this->assertMatchesRegularExpression('#^/[a-z-]+( [a-z-]+)*$#', $menu);
        }
    }
}
