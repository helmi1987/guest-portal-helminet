<?php

namespace App\Nas\RouterOs;

/**
 * RouterOS API over TLS (service api-ssl), minimal and print only: login (name + password, RouterOS
 * >= 6.43), "/<menu>/print" with =.proplist= and ?queries, answers !re / !done / !trap / !fatal
 * (and !empty of RouterOS >= 7.18). The server's public key must match the pin before the credentials
 * are sent. One connection per query, closed afterwards.
 */
final class ApiSslClient implements RouterOsClient
{
    private ?ApiConnection $connection = null;

    public function __construct(
        private readonly ApiConnector $connector,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $pin,
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly int $maxRows = 2000,
    ) {}

    public function print(string $menu, array $proplist, array $where = []): array
    {
        $segments = Menus::guard($menu, $proplist, $where);
        $words = ['/'.implode('/', $segments).'/print', '=.proplist='.implode(',', $proplist)];
        foreach ($where as $condition) {
            $words[] = '?'.$condition;
        }
        if (count($where) > 1) {
            $words[] = '?#'.str_repeat('|', count($where) - 1);
        }
        $connection = $this->connect();
        $connection->write(ApiSentence::encode($words));

        $rows = [];
        $trap = null;
        while (true) {
            $reply = ApiSentence::read($connection);
            $type = $reply[0] ?? '';
            $attrs = self::attributes(array_slice($reply, 1));
            if ($type === '!re') {
                if (count($rows) < $this->maxRows + 1) {
                    $rows[] = array_intersect_key($attrs, array_flip($proplist));
                }
            } elseif ($type === '!trap') {
                $trap ??= $attrs['message'] ?? 'error';
            } elseif ($type === '!fatal') {
                $this->close();
                throw new RouterOsException('protocol', 'fatal: '.mb_substr(implode(' ', array_slice($reply, 1)), 0, 200));
            } elseif ($type === '!done' || $type === '!empty') {
                if ($type === '!empty') {
                    continue; // followed by !done
                }
                break;
            }
        }
        if ($trap !== null) {
            $lower = strtolower($trap);
            throw new RouterOsException(str_contains($lower, 'not enough permissions') || str_contains($lower, 'permission') ? 'denied'
                : (str_contains($lower, 'no such command') ? 'menu' : 'protocol'), mb_substr($trap, 0, 200));
        }

        return $rows;
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    private function connect(): ApiConnection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $connection = $this->connector->open($this->host, $this->port, $this->connectTimeout, $this->timeout);
        $pin = $connection->peerPin();
        if ($pin === null || ! hash_equals($this->pin, $pin)) {
            $connection->close();
            throw new RouterOsException('tls', 'the router certificate does not match the pinned key');
        }
        $connection->write(ApiSentence::encode(['/login', '=name='.$this->user, '=password='.$this->password]));
        $trapped = false;
        $attrs = [];
        do {
            $reply = ApiSentence::read($connection);
            $type = $reply[0] ?? '';
            $trapped = $trapped || $type === '!trap';
            if ($type === '!done') {
                $attrs = self::attributes(array_slice($reply, 1));
            }
        } while ($type !== '!done' && $type !== '!fatal');
        if ($trapped || $type !== '!done') {
            $connection->close();
            throw new RouterOsException('auth', 'login refused');
        }
        // Pre-6.43 challenge login (=ret=) is not supported
        if (isset($attrs['ret'])) {
            $connection->close();
            throw new RouterOsException('protocol', 'RouterOS before 6.43 (challenge login) is not supported');
        }

        return $this->connection = $connection;
    }

    /** @return array<string, string> "=name=value" words as name => value */
    private static function attributes(array $words): array
    {
        $out = [];
        foreach ($words as $word) {
            if (str_starts_with($word, '=')) {
                $pos = strpos($word, '=', 1);
                if ($pos !== false) {
                    $out[substr($word, 1, $pos - 1)] = substr($word, $pos + 1);
                }
            }
        }

        return $out;
    }
}
