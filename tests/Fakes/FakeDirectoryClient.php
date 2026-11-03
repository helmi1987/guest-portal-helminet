<?php

namespace Tests\Fakes;

use App\Auth\Directory\DirectoryClient;
use App\Auth\Directory\DirectoryConfig;
use App\Auth\Directory\DirectoryException;

/**
 * In-memory directory for tests: entries with DN, password and attributes, a service account, and
 * failures to simulate (connect, search, a restricted account). Filters are matched by the escaped user
 * name inside them, so escaping is exercised. Records connections, binds (DN only) and searches.
 */
class FakeDirectoryClient implements DirectoryClient
{
    /** @var array<string, array{dn: string, password: string, attributes: array<string, list<string>>, restricted?: bool}> */
    public array $users = [];

    public string $serviceDn = 'CN=svc-portal,OU=Service,DC=example,DC=com';

    public string $servicePassword = 'service-secret';

    /** Reason thrown by connect() (e.g. DirectoryException::UNREACHABLE, ::TLS), null: connects. */
    public ?string $connectFailure = null;

    /** Reason thrown by search(), null: searches. */
    public ?string $searchFailure = null;

    /** @var list<DirectoryConfig> */
    public array $connections = [];

    /** @var list<string> bound DNs */
    public array $binds = [];

    /** @var list<string> search filters */
    public array $filters = [];

    public bool $connected = false;

    public function addUser(string $username, #[\SensitiveParameter] string $password, array $attributes = [], bool $restricted = false): static
    {
        $this->users[$username] = [
            'dn' => 'CN='.$username.',OU=Staff,DC=example,DC=com',
            'password' => $password,
            'attributes' => array_change_key_case(array_map(fn ($v) => (array) $v, $attributes)),
            'restricted' => $restricted,
        ];

        return $this;
    }

    public function connect(DirectoryConfig $config): void
    {
        $this->connections[] = $config;
        if ($this->connectFailure !== null) {
            throw new DirectoryException($this->connectFailure, 'fake connect failure');
        }
        $this->connected = true;
    }

    public function bind(string $dn, #[\SensitiveParameter] string $password): void
    {
        $this->binds[] = $dn;
        if ($dn === '') {
            return;
        }
        if ($dn === $this->serviceDn) {
            if (! hash_equals($this->servicePassword, $password)) {
                throw new DirectoryException(DirectoryException::INVALID_CREDENTIALS, 'fake: wrong service password');
            }

            return;
        }
        foreach ($this->users as $user) {
            if ($user['dn'] === $dn) {
                if ($user['restricted'] ?? false) {
                    throw new DirectoryException(DirectoryException::ACCOUNT_RESTRICTED, 'fake: data 775');
                }
                if ($password !== '' && hash_equals($user['password'], $password)) {
                    return;
                }
            }
        }
        throw new DirectoryException(DirectoryException::INVALID_CREDENTIALS, 'fake: invalid credentials');
    }

    public function search(string $baseDn, string $filter, array $attributes): array
    {
        $this->filters[] = $filter;
        if ($this->searchFailure !== null) {
            throw new DirectoryException($this->searchFailure, 'fake search failure');
        }
        $wanted = array_map('strtolower', $attributes);
        $out = [];
        foreach ($this->users as $username => $user) {
            if (str_contains($filter, '='.DirectoryConfig::escapeFilterValue($username).')')) {
                $out[] = ['dn' => $user['dn'], 'attributes' => array_intersect_key($user['attributes'], array_flip($wanted))];
            }
        }

        return $out;
    }

    public function close(): void
    {
        $this->connected = false;
    }
}
