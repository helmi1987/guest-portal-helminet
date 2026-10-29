<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Fail a test that writes a replicated table without an outbox event (FederationWriteGuard). */
    protected bool $federationWriteGuard = true;

    private bool $federationWriteGuardInstalled = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['portal.radius.clients_file' => sys_get_temp_dir().'/portal-test-'.getmypid().'/clients.conf',
            // Fresh (not yet existing) branding folder per test.
            'portal.branding.dir' => sys_get_temp_dir().'/portal-test-'.getmypid().'/branding-'.bin2hex(random_bytes(4))]);

        if ($this->federationWriteGuard && DB::getDriverName() === 'sqlite'
            && in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            FederationWriteGuard::install();
            $this->federationWriteGuardInstalled = true;
        }
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        if ($this->federationWriteGuardInstalled && $this->federationWriteGuard) {
            $this->assertSame([], FederationWriteGuard::violations(), 'Replicated rows written without an outbox event.');
        }
    }

    /** Disable the guard for a test that writes replicated rows raw on purpose. */
    protected function withoutFederationWriteGuard(): void
    {
        $this->federationWriteGuard = false;
    }

    /** Simulate the nginx listener (FastCGI param PORTAL_ZONE). */
    protected function onZone(string $zone): static
    {
        return $this->withServerVariables(['PORTAL_ZONE' => $zone]);
    }
}
