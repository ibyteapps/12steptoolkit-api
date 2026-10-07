<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
|
| One cron entry runs this: `* * * * * cd <api> && php artisan schedule:run`.
*/

// Proof the scheduler is running at all, read back by `app:check`.
Schedule::call(fn () => cache()->put('scheduler-heartbeat', now()->toIso8601String(), now()->addHour()))
    ->everyMinute()->name('scheduler-heartbeat');

// The queue worker, when there is no supervisor. One minute of work at a time,
// never two at once.
Schedule::command('queue:work --sleep=2 --tries=3 --backoff=10 --max-time=55')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(2)
    ->when(fn () => config('toolkit.ops.queue_worker_in_scheduler') && config('queue.default') !== 'sync');

// Housekeeping.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:20');
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:30');
