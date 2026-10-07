<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One recurring reminder: a morning one, a nightly one, or the hourly nudge.
 *
 * `UNIQUE (account_id, type, hhmm)`, so writing one is an upsert on that triple.
 * `days_mask` is a 7-bit field defaulting to 127 (every day), bit 0 = Monday.
 *
 * `next_fire_at` is computed and stored rather than worked out at send time,
 * because the sender is one indexed query — `WHERE enabled = 1 AND next_fire_at
 * <= now()` — and a timezone calculation per subscriber per minute is not.
 */
class ReminderSubscription extends Model
{
    protected $table = 'reminder_subscriptions';

    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    public const MORNING = 'MORNING';

    public const NIGHT = 'NIGHT';

    public const HOURLY = 'HOURLY';

    public const TYPES = [self::MORNING, self::NIGHT, self::HOURLY];

    public const EVERY_DAY = 127;

    protected function casts(): array
    {
        return [
            'days_mask' => 'int',
            'enabled' => 'bool',
            'snoozed_until' => 'datetime',
            'last_fired_at' => 'datetime',
            'next_fire_at' => 'datetime',
        ];
    }

    /** Does this reminder run on this day? Bit 0 is Monday, as `days_mask` has it. */
    public function runsOn(Carbon $day): bool
    {
        // Carbon: 1 = Monday … 7 = Sunday.
        return ($this->days_mask & (1 << ($day->isoWeekday() - 1))) !== 0;
    }

    /**
     * The next time this should fire, in UTC.
     *
     * Worked out in the subscriber's own timezone and then converted, which is
     * the only way "half past seven in the morning" survives a clock change: on
     * the day the clocks go forward, 07:30 local is a different UTC instant
     * before and after, and storing UTC alone would drift by an hour twice a
     * year.
     */
    public function nextFireAt(?Carbon $after = null): ?Carbon
    {
        $zone = $this->timezone !== '' && $this->timezone !== null ? $this->timezone : 'UTC';

        try {
            $from = ($after ?? now())->copy()->setTimezone($zone);
        } catch (\Throwable) {
            $from = ($after ?? now())->copy()->setTimezone('UTC');
            $zone = 'UTC';
        }

        if ($this->days_mask === 0) {
            return null;
        }

        [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) $this->hhmm)), 2, 0);

        // The hourly nudge runs on the hour between its two bounds, so its
        // `hhmm` is a start time and the next slot is simply the next hour.
        $candidate = $from->copy()->setTime($hour, $minute, 0);

        for ($i = 0; $i <= 8; $i++) {
            $day = $candidate->copy()->addDays($i);

            if ($day->greaterThan($from) && $this->runsOn($day)) {
                return $day->setTimezone('UTC');
            }
        }

        return null;
    }
}
