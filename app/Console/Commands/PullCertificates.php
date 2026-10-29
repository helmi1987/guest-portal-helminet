<?php

namespace App\Console\Commands;

use App\Tls\CertificatePuller;
use App\Tls\CertificateSources;
use Illuminate\Console\Command;

/**
 * Pulls the portal and router certificates from their configured sources (scheduled every 6 h,
 * CONCEPT.md §6). Targets without a source are skipped.
 */
class PullCertificates extends Command
{
    protected $signature = 'portal:cert-pull {target? : portal or router (default: all with a source)}';

    protected $description = 'Pull certificates from CertWarden or a certificate URL';

    public function handle(CertificatePuller $puller): int
    {
        $target = $this->argument('target');
        if ($target !== null && ! in_array($target, CertificateSources::TARGETS, true)) {
            $this->error('Unknown target, use: '.implode(', ', CertificateSources::TARGETS));

            return self::INVALID;
        }

        $results = $target === null ? $puller->pullAll() : rescue(fn () => [$target => $puller->pull($target)],
            [$target => CertificatePuller::FAILED], false);
        foreach ($results as $name => $result) {
            $line = "$name: $result";
            $result === CertificatePuller::FAILED
                ? $this->error($line.' ('.(CertificateSources::status($name)['error'] ?? '').')')
                : $this->info($line);
        }

        return in_array(CertificatePuller::FAILED, $results, true) ? self::FAILURE : self::SUCCESS;
    }
}
