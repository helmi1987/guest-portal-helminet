<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['portal.radius.clients_file' => sys_get_temp_dir().'/portal-test-'.getmypid().'/clients.conf',
            // Fresh (not yet existing) branding folder per test.
            'portal.branding.dir' => sys_get_temp_dir().'/portal-test-'.getmypid().'/branding-'.bin2hex(random_bytes(4))]);
    }

    /** Simulate the nginx listener (FastCGI param PORTAL_ZONE). */
    protected function onZone(string $zone): static
    {
        return $this->withServerVariables(['PORTAL_ZONE' => $zone]);
    }
}
