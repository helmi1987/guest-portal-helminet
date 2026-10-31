<?php

namespace Tests\Unit\RouterOs;

use App\Nas\RouterOs\ApiConnection;
use App\Nas\RouterOs\ApiConnector;
use App\Nas\RouterOs\ApiSentence;
use App\Nas\RouterOs\RouterOsException;
use Closure;

/**
 * Scripted RouterOS API-SSL server for tests: decodes every sentence the client writes and answers
 * with the sentences returned by the handler (list of word lists). Records all received sentences.
 */
final class FakeApiConnection implements ApiConnection
{
    /** @var list<list<string>> */
    public array $received = [];

    public bool $closed = false;

    private string $out = '';

    /** @param Closure(list<string>): list<list<string>> $handler */
    public function __construct(private readonly Closure $handler, private readonly ?string $pin) {}

    public static function connector(self $connection): ApiConnector
    {
        return new class($connection) extends ApiConnector
        {
            public array $opened = [];

            public function __construct(private readonly FakeApiConnection $connection) {}

            public function open(string $host, int $port, int $connectTimeout, int $timeout): ApiConnection
            {
                $this->opened[] = "{$host}:{$port}";

                return $this->connection;
            }
        };
    }

    public function peerPin(): ?string
    {
        return $this->pin;
    }

    public function write(string $bytes): void
    {
        $in = new class($bytes) implements ApiConnection
        {
            public function __construct(private string $buffer) {}

            public function peerPin(): ?string
            {
                return null;
            }

            public function write(string $bytes): void {}

            public function read(int $length): string
            {
                if (strlen($this->buffer) < $length) {
                    throw new RouterOsException('protocol', 'short sentence');
                }
                $chunk = substr($this->buffer, 0, $length);
                $this->buffer = substr($this->buffer, $length);

                return $chunk;
            }

            public function close(): void {}

            public function rest(): string
            {
                return $this->buffer;
            }
        };
        do {
            $sentence = ApiSentence::read($in);
            $this->received[] = $sentence;
            foreach (($this->handler)($sentence) as $reply) {
                $this->out .= ApiSentence::encode($reply);
            }
        } while ($in->rest() !== '');
    }

    public function read(int $length): string
    {
        if (strlen($this->out) < $length) {
            throw new RouterOsException('timeout', 'no answer from the router');
        }
        $chunk = substr($this->out, 0, $length);
        $this->out = substr($this->out, $length);

        return $chunk;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * Handler emulating a router: login with the given credentials, print of the given menus
     * (menu path => rows), "no such command prefix" for others.
     *
     * @param  array<string, list<array<string, string>>>  $menus
     */
    public static function router(string $user, string $password, array $menus): Closure
    {
        return function (array $s) use ($user, $password, $menus): array {
            $cmd = $s[0] ?? '';
            if ($cmd === '/login') {
                return in_array('=name='.$user, $s, true) && in_array('=password='.$password, $s, true)
                    ? [['!done']] : [['!trap', '=message=invalid user name or password (6)'], ['!done']];
            }
            $menu = str_replace('/', ' ', substr($cmd, 0, -strlen('/print')));
            if (! str_ends_with($cmd, '/print') || ! array_key_exists('/'.ltrim($menu), $menus)) {
                return [['!trap', '=message=no such command prefix'], ['!done']];
            }
            $props = [];
            foreach ($s as $w) {
                if (str_starts_with($w, '=.proplist=')) {
                    $props = explode(',', substr($w, 11));
                }
            }
            $rows = $menus['/'.ltrim($menu)];
            if ($rows === []) {
                return [['!empty'], ['!done']];
            }
            $out = [];
            foreach ($rows as $i => $row) {
                $words = ['!re', '=.id=*'.dechex($i + 1)];
                foreach ($row as $k => $v) {
                    if (in_array($k, $props, true)) {
                        $words[] = "={$k}={$v}";
                    }
                }
                $out[] = $words;
            }
            $out[] = ['!done'];

            return $out;
        };
    }
}
