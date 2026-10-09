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
/*
 | The reminders. `sync_reminders.php` has always written
 | `reminder_subscriptions`; until now nothing read it, so the morning and
 | nightly reminders members had set were recorded and never delivered.
 |
 | Every minute, because a reminder set for 07:30 should arrive at 07:30.
 | `withoutOverlapping` so a slow run cannot have two senders reading the same
 | due rows.
 */
Schedule::command('reminders:send')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('reminders-send');

Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:20');
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:30');
