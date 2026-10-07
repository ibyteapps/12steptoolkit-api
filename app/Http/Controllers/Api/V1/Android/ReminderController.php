<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\AccountDetail;
use App\Models\ReminderSubscription;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `sync_reminders.php` — rebuild somebody's reminder schedule from their
 * settings.
 *
 * The settings live in `account_details` as four `varchar(5)` times —
 * `notification_morning`, `notification_night`, `notification_hourly_start` and
 * `notification_hourly_end` — plus a timezone. This turns them into rows in
 * `reminder_subscriptions` with a computed `next_fire_at`, which is what the
 * sender reads.
 *
 * ## What changes
 *
 * The old script opens with:
 *
 *     define('REQUIRE_AUTH', false);
 *     define('REQUIRE_HMAC', false);
 *
 * and then takes `account_id` from the body. So it is an unauthenticated write
 * endpoint for any account, and it answers "Account not found." for an id that
 * does not exist, which makes it an account-existence oracle as well. Neither
 * does much harm on its own — the worst a stranger can do is recompute your
 * reminders from your own settings — but there is no reason for it, and the
 * oracle is worth closing.
 *
 * Here it is behind the signature like everything else, and it syncs the
 * **caller's** reminders.
 *
 * `next_fire_at` is computed in the subscriber's own timezone and stored in UTC.
 * {@see ReminderSubscription::nextFireAt} says why that matters twice a year.
 */
class ReminderController extends Controller
{
    public const ENDPOINTS = [
        'sync_reminders.php' => 'sync',
    ];

    public function sync(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);
        $details = AccountDetail::query()->where('accountid', $account->id)->first();

        if ($details === null) {
            return LegacyEnvelope::fail('No settings to sync', 404);
        }

        $timezone = $this->timezone($details, $request);

        $wanted = [
            ReminderSubscription::MORNING => $this->time($details->notification_morning),
            ReminderSubscription::NIGHT => $this->time($details->notification_night),
            // The hourly nudge's `hhmm` is its start; its window ends at
            // `notification_hourly_end` and the sender steps through the hours
            // between. Only the start is a schedule.
            ReminderSubscription::HOURLY => $this->time($details->notification_hourly_start),
        ];

        DB::transaction(function () use ($account, $details, $wanted, $timezone): void {
            foreach ($wanted as $type => $hhmm) {
                if ($hhmm === null) {
                    // Cleared in the app: switch the reminder off rather than
                    // deleting it, so a snooze or a last-fired time survives
                    // being turned off and on again.
                    ReminderSubscription::query()
                        ->where('account_id', $account->id)
                        ->where('type', $type)
                        ->update(['enabled' => false, 'modified' => now()]);

                    continue;
                }

                // Found then built, not `firstOrNew`: that mass-assigns, and
                // every model here guards everything so nothing can be written
                // into a person's data by accident.
                $row = ReminderSubscription::query()
                    ->where('account_id', $account->id)
                    ->where('type', $type)
                    ->where('hhmm', $hhmm)
                    ->first() ?? new ReminderSubscription;

                $row->forceFill([
                    'account_id' => $account->id,
                    'type' => $type,
                    'hhmm' => $hhmm,
                    'timezone' => $timezone,
                    'language' => (string) ($row->language ?: $details->language ?: 'en'),
                    'days_mask' => $row->days_mask ?: ReminderSubscription::EVERY_DAY,
                    'enabled' => true,
                ]);

                $row->next_fire_at = $row->nextFireAt();
                $row->save();

                // Any other time for the same type is a leftover from before
                // they changed it.
                ReminderSubscription::query()
                    ->where('account_id', $account->id)
                    ->where('type', $type)
                    ->where('hhmm', '!=', $hhmm)
                    ->update(['enabled' => false, 'modified' => now()]);
            }
        });

        $counts = ReminderSubscription::query()
            ->where('account_id', $account->id)
            ->where('enabled', true)
            ->select('type', DB::raw('COUNT(*) AS cnt'))
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->all();

        return LegacyEnvelope::ok([
            'counts' => $counts,
            'timezone' => $timezone,
        ], 'Reminders synced');
    }

    /**
     * `HH:MM`, or null for anything that is not one.
     *
     * The column is a `varchar(5)` with no constraint, so it holds whatever the
     * app has ever written: an empty string for "off", and in older rows
     * occasionally a stray value. Anything unparseable is treated as off rather
     * than scheduled at midnight.
     */
    private function time(?string $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    /**
     * Their timezone, preferring what the request says over what is stored.
     *
     * Somebody who has flown is in a new timezone before `account_details`
     * knows, and a reminder at half past seven in the wrong country is the
     * complaint this prevents.
     */
    private function timezone(AccountDetail $details, Request $request): string
    {
        foreach ([$request->input('timezone'), $request->header('X-Timezone'), $details->timezone] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }
}
