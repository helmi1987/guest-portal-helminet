<?php

namespace App\Console\Commands;

use App\Models\NasClient;
use App\Nas\RouterCheck;
use App\Nas\RouterFreshness;
use App\Nas\RouterNotifier;
use Illuminate\Console\Command;

/**
 * Scheduled router check (twice a day, decision 2026-11-05, v0.30.0): state query of the routers with API access
 * served here, page fingerprints, notification when a router becomes outdated or unreachable.
 */
class CheckRouterState extends Command
{
    protected $signature = 'portal:router-state-check';

    protected $description = 'Check whether the routers of this instance have outdated hotspot pages or configuration';

    public function handle(RouterCheck $check): int
    {
        $count = $check->run(function (NasClient $nas, array $result, bool $sent) {
            $what = match (true) {
                $result['unreachable'] => 'unreachable',
                $result['outdated'] => 'outdated'.($result['pages'] !== [] ? ' pages: '.RouterNotifier::files($result['pages']) : '')
                    .($result['blocks'] !== [] ? ' blocks: '.implode(', ', $result['blocks']) : ''),
                $result['config'] === RouterFreshness::CONFIG_PENDING => 'up to date (configuration check pending)',
                default => 'up to date',
            };
            $this->line("{$nas->site->name} / {$nas->name}: {$what}".($sent ? ' (notified)' : ''));
        });
        if ($count === null) {
            $this->warn('Another router check is running.');
        }

        return self::SUCCESS;
    }
}
