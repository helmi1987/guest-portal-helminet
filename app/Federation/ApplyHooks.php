<?php

namespace App\Federation;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-table hooks run after applied remote changes are committed (CONCEPT.md §10.3, §10.4), e.g.
 * disconnects or branding file fetches (later parts). A failing hook is logged and does not undo the
 * applied change.
 */
class ApplyHooks
{
    /** @var array<string, list<callable(AppliedChange): void>> */
    private array $hooks = [];

    /** @param  callable(AppliedChange): void  $hook */
    public function register(string $table, callable $hook): void
    {
        $this->hooks[$table][] = $hook;
    }

    public function dispatch(AppliedChange $change): void
    {
        foreach ($this->hooks[$change->table] ?? [] as $hook) {
            try {
                $hook($change);
            } catch (Throwable $e) {
                Log::error('Federation apply hook failed', ['table' => $change->table, 'id' => $change->id,
                    'error' => $e->getMessage()]);
            }
        }
    }
}
