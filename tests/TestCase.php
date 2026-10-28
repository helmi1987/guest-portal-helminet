<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Simulate the nginx listener (FastCGI param PORTAL_ZONE). */
    protected function onZone(string $zone): static
    {
        return $this->withServerVariables(['PORTAL_ZONE' => $zone]);
    }
}
