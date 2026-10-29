<?php

use Illuminate\Support\Facades\Schedule;

// Run by the scheduler service (artisan schedule:work).
Schedule::command('portal:enforce-grants')->everyMinute()->withoutOverlapping();
Schedule::command('portal:purge')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('portal:backup')->dailyAt('02:45')->withoutOverlapping();
Schedule::command('portal:cert-pull')->cron('23 */6 * * *')->withoutOverlapping();
// Federation (CONCEPT.md §10.2): no-op without other instances.
Schedule::command('portal:federation:sync')->everyThirtySeconds()->withoutOverlapping(5);
