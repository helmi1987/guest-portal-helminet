<?php

namespace App\Federation;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A write of a replicated row while the restore resync is not finished (CONCEPT.md §10.3): the own
 * sequence would collide with events the peers already have. Answered as 503 (try again shortly).
 */
class ResyncRequiredException extends HttpException
{
    public function __construct()
    {
        parent::__construct(503, 'Federation resync after restore in progress: replicated data is read-only until it completes.');
    }
}
