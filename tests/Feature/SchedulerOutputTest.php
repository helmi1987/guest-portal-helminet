<?php

namespace Tests\Feature;

use App\Console\SchedulerOutput;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Output of the scheduled commands: appended (stdout and stderr) to the scheduler's own output, never the
 * default /dev/null, so their console and log lines reach the container log.
 */
class SchedulerOutputTest extends TestCase
{
    public function test_every_scheduled_event_appends_stdout_and_stderr_to_the_scheduler_output(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);
        $target = SchedulerOutput::target();
        foreach ($events as $event) {
            $this->assertNotSame($event->getDefaultOutput(), $event->output, $event->command);
            $this->assertSame($target, $event->output, $event->command);
            $this->assertTrue($event->shouldAppendOutput, $event->command);
            // Laravel redirects stderr to the same target
            $this->assertStringEndsWith(' >> '.escapeshellarg($target).' 2>&1', $event->buildCommand(), $event->command);
        }
    }

    public function test_target_is_the_stdout_of_the_scheduler_process_or_a_log_file_without_proc(): void
    {
        $proc = sys_get_temp_dir().'/gp-proc-'.bin2hex(random_bytes(4));
        try {
            $this->assertSame(storage_path('logs/scheduler.log'), SchedulerOutput::target($proc));
            File::makeDirectory($proc.'/'.getmypid().'/fd', 0700, true);
            $this->assertSame($proc.'/'.getmypid().'/fd/1', SchedulerOutput::target($proc));
        } finally {
            File::deleteDirectory($proc);
        }
    }
}
