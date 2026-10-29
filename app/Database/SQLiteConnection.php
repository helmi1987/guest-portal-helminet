<?php

namespace App\Database;

use Illuminate\Database\SQLiteConnection as BaseConnection;

/**
 * SQLite connection honouring `transaction_mode` also below PHP 8.4 (Laravel uses
 * `BEGIN <mode>` only from PHP 8.4 on; the container runs 8.4). For IMMEDIATE, a header write
 * (`PRAGMA user_version`, unused by the app) right after BEGIN takes the write lock at once, so reads
 * inside the transaction cannot go stale before its first write (SQLITE_BUSY_SNAPSHOT under WAL).
 */
class SQLiteConnection extends BaseConnection
{
    protected function executeBeginTransactionStatement()
    {
        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            parent::executeBeginTransactionStatement();

            return;
        }

        $this->getPdo()->beginTransaction();
        if (strtoupper((string) $this->getConfig('transaction_mode')) === 'IMMEDIATE') {
            $this->getPdo()->exec('PRAGMA user_version = 0');
        }
    }
}
