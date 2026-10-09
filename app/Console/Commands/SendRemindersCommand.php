<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;

/**
 * `php artisan reminders:send` — the half of the reminder feature that was
 * missing.
 *
 * Runs every minute from the scheduler. Everything it does is in
 * {@see ReminderDispatcher}; this is the handle.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'reminders:send {--limit=500 : Most reminders to send in one run}';

    protected $description = 'Send the morning, nightly and hourly reminders that are due';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $stats = $dispatcher->dispatch(limit: (int) $this->option('limit'));

        $this->line(sprintf(
            'due %d, sent %d, no device %d (driver: %s)',
            $stats['due'], $stats['sent'], $stats['no_device'], config('push.driver'),
        ));

        return self::SUCCESS;
    }
}
