<?php

use Illuminate\Support\Facades\Schedule;

// Run by the scheduler service (artisan schedule:work).
Schedule::command('portal:enforce-grants')->everyMinute()->withoutOverlapping();
Schedule::command('portal:purge')->dailyAt('03:30')->withoutOverlapping();
