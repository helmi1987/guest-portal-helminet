<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Output of the scheduled commands. Laravel runs each one as "<command> > /dev/null 2>&1" unless an output
 * target is set, so its console lines and its log lines (LOG_CHANNEL=stderr in the container) were lost.
 * Every event appends stdout and stderr (Laravel adds "2>&1" to the target) to the standard output of
 * the process that runs the schedule (artisan schedule:run). schedule:work, the scheduler service of the
 * container, copies that output to its own, i.e. the container log. "/proc/self/fd/1" would not do: in
 * the event's shell it is the pipe that schedule:run reads and discards. Without /proc (macOS in
 * development) the output goes to storage/logs/scheduler.log.
 */
final class SchedulerOutput
{
    /** File the scheduled commands append their output to (see the class comment). */
    public static function target(string $proc = '/proc'): string
    {
        $fd = $proc.'/'.getmypid().'/fd';

        return is_dir($fd) ? $fd.'/1' : storage_path('logs/scheduler.log');
    }

    /** Append the output of every scheduled event to target() (call after all events are defined). */
    public static function apply(Schedule $schedule, ?string $target = null): void
    {
        $target ??= self::target();
        foreach ($schedule->events() as $event) {
            $event->appendOutputTo($target);
        }
    }
}
