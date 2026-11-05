<?php

use App\Console\SchedulerOutput;
use Illuminate\Support\Facades\Schedule;

// Run by the scheduler service (artisan schedule:work).
Schedule::command('portal:enforce-grants')->everyMinute()->withoutOverlapping();
// Controller sites (UniFi, experimental): usage and IP from the controller; no-op without such sites.
Schedule::command('portal:poll-controllers')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('portal:purge')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('portal:backup')->dailyAt('02:45')->withoutOverlapping();
Schedule::command('portal:cert-pull')->cron('23 */6 * * *')->withoutOverlapping();
// Outdated hotspot pages or router configuration (decision 2026-11-05, v0.30.0): read-only, notifies on the transition.
Schedule::command('portal:router-state-check')->cron('17 6,18 * * *')->withoutOverlapping();
// Federation (CONCEPT.md §10.2): no-op without other instances.
Schedule::command('portal:federation:sync')->everyThirtySeconds()->withoutOverlapping(5);

// Output and log lines of every scheduled command into the scheduler's output (container log), not /dev/null.
SchedulerOutput::apply(Schedule::getFacadeRoot());
