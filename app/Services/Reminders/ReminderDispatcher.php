<?php

namespace App\Services\Reminders;

use App\Models\AccountDetail;
use App\Models\ReminderSubscription;
use App\Services\Push\DeviceTokens;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sending the reminders that `sync_reminders.php` has been recording.
 *
 * `reminder_subscriptions` was written by the sync endpoint and read by
 * nothing: the morning and nightly reminders were stored, scheduled, and
 * never delivered. This is the half that was missing.
 *
 * ## Due
 *
 * `enabled`, `next_fire_at` in the past, and not snoozed. The table already
 * carries `idx_due` on `(enabled, next_fire_at)` for exactly this query.
 *
 * ## Advancing
 *
 * The schedule moves on **whether or not the send succeeded**, and whether or
 * not a push driver is configured. The alternative is worse: a row whose
 * `next_fire_at` stays in the past accumulates, and the day somebody fixes
 * the key the member gets a week of reminders at once. A missed reminder is a
 * missed reminder; it is not owed.
 *
 * ## The hourly nudge
 *
 * `MORNING` and `NIGHT` are once a day, so the model's own `nextFireAt()`
 * answers for them. `HOURLY` is not: its `hhmm` is the *start* of a window
 * that ends at `account_details.notification_hourly_end`, and it fires on the
 * hour in between. So its next fire is an hour later while that is still
 * inside the window, and the start of the next day otherwise.
 */
class ReminderDispatcher
{
    public function __construct(
        private readonly PushSender $push,
        private readonly DeviceTokens $tokens,
        private readonly ReminderTexts $texts,
    ) {}

    /**
     * Send everything due at `$now`.
     *
     * @return array{due: int, sent: int, no_device: int}
     */
    public function dispatch(?Carbon $now = null, int $limit = 500): array
    {
        $now ??= now();
        $stats = ['due' => 0, 'sent' => 0, 'no_device' => 0];

        $due = ReminderSubscription::query()
            ->where('enabled', true)
            ->whereNotNull('next_fire_at')
            ->where('next_fire_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now))
            ->orderBy('next_fire_at')
            ->limit($limit)
            ->get();

        foreach ($due as $reminder) {
            $stats['due']++;

            $tokens = $this->tokens->for((int) $reminder->account_id);

            if ($tokens === []) {
                // Nothing to send to. The schedule still moves on, or this row
                // is re-read every minute for ever.
                $stats['no_device']++;
                $this->advance($reminder, $now);

                continue;
            }

            try {
                $this->push->send($tokens, new PushMessage(
                    title: $this->texts->title((string) $reminder->type),
                    body: $this->texts->body((string) $reminder->type, (string) ($reminder->language ?: 'en')),
                    data: ['type' => (string) $reminder->type],
                ));
                $stats['sent']++;
            } catch (\Throwable $e) {
                // One member's bad token must not stop the run.
                Log::warning('reminder send failed', [
                    'reminder_id' => (int) $reminder->id,
                    'reason' => $e->getMessage(),
                ]);
            }

            $this->advance($reminder, $now);
        }

        return $stats;
    }

    private function advance(ReminderSubscription $reminder, Carbon $now): void
    {
        $next = (string) $reminder->type === ReminderSubscription::HOURLY
            ? $this->nextHourly($reminder, $now)
            : $reminder->nextFireAt($now);

        $reminder->forceFill([
            'last_fired_at' => $now,
            'next_fire_at' => $next,
            'modified' => $now,
        ])->save();
    }

    /**
     * The next slot inside the hourly window, or tomorrow's start.
     *
     * Falls back to the model's day-stepping when there is no window to read,
     * which is what an account with no `account_details` row looks like.
     */
    private function nextHourly(ReminderSubscription $reminder, Carbon $now): ?Carbon
    {
        $end = AccountDetail::query()
            ->where('accountid', $reminder->account_id)
            ->value('notification_hourly_end');

        if (! is_string($end) || ! preg_match('/^\d{1,2}:\d{2}$/', $end)) {
            return $reminder->nextFireAt($now);
        }

        $zone = (string) ($reminder->timezone ?: 'UTC');

        try {
            $local = $now->copy()->setTimezone($zone);
        } catch (\Throwable) {
            $local = $now->copy();
            $zone = 'UTC';
        }

        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $candidate = $local->copy()->addHour()->startOfHour();
        $windowEnd = $local->copy()->setTime($endHour, $endMinute, 0);

        if ($candidate->lessThanOrEqualTo($windowEnd) && $reminder->runsOn($candidate)) {
            return $candidate->setTimezone('UTC');
        }

        return $reminder->nextFireAt($now);
    }
}
