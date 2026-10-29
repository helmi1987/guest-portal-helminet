<?php

namespace App\Console\Commands;

use App\Backup\BackupManager;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates a backup and applies the retention count (scheduled daily, CONCEPT.md §9).
 */
class BackupPortal extends Command
{
    protected $signature = 'portal:backup';

    protected $description = 'Back up database, env file and certificates to the backup folder';

    public function handle(BackupManager $backups): int
    {
        try {
            $backup = $backups->create();
        } catch (Throwable $e) {
            Log::error('Backup failed', ['error' => $e->getMessage()]);
            Audit::log('backup.failed', null, ['error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $pruned = $backups->prune();
        Audit::log('backup.created', null, ['file' => $backup['name'], 'size' => $backup['size'],
            'skipped' => $backup['skipped'], 'pruned' => $pruned]);

        $this->info($backups->dir().'/'.$backup['name']);
        foreach ($backup['skipped'] as $file) {
            $this->warn("Not readable, skipped: $file");
        }

        return self::SUCCESS;
    }
}
