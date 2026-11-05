<?php

namespace App\Jobs;

use App\Nas\RouterCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One router check after a portal version change (RouterCheck::afterVersionChange()), on the low-priority queue of
 * the router state queries so SMS, disconnects and pushes are not delayed.
 */
class CheckRouters implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Several routers one after another, each bounded by the query deadline. */
    public int $timeout = 1800;

    public function __construct()
    {
        $this->onQueue(QueryRouterState::QUEUE);
    }

    public function handle(RouterCheck $check): void
    {
        $check->run();
    }
}
