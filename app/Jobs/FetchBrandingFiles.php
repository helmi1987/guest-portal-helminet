<?php

namespace App\Jobs;

use App\Federation\BrandingFiles;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetch branding files referenced by replicated settings but missing here (CONCEPT.md §10.3).
 * Dispatched by the apply hooks; the sync run retries failures.
 */
class FetchBrandingFiles implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function handle(BrandingFiles $files): void
    {
        $files->fetchMissing(force: true);
    }
}
