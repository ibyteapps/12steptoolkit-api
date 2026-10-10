<?php

namespace App\Services\Reminders;

use App\Models\ReminderSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The wording of a reminder, from `notification_texts`.
 *
 * The table holds many lines per type and language, and its `ix_pick_order`
 * index — `(type, language, times_used, last_used)` — says how they are meant
 * to be chosen: least used first, oldest first to break the tie. So somebody
 * who has had a nightly reminder for three years is not read the same
 * sentence a thousand times.
 *
 * Picking bumps the counters, which is what makes the rotation work. That is
 * a write on a read path, and it is why this is a class rather than a query
 * in the dispatcher.
 *
 * There is no title here, deliberately. A reminder is sent data-only and both
 * clients write their own heading — the old one from its string resources,
 * the new one from `NotificationCatalog` — so a title composed on the server
 * would be carried across the world and thrown away. The body is sent because
 * the old client does display it (`data['text']`), and because the rotation
 * above is the whole point of the table.
 */
class ReminderTexts
{
    private const FALLBACK = [
        ReminderSubscription::MORNING => 'Time for your morning inventory.',
        ReminderSubscription::NIGHT => 'Time for your nightly inventory.',
        ReminderSubscription::HOURLY => 'A moment to check in with yourself.',
    ];

    public function body(string $type, string $language = 'en'): string
    {
        if (! Schema::hasTable('notification_texts')) {
            return self::FALLBACK[$type] ?? self::FALLBACK[ReminderSubscription::HOURLY];
        }

        $row = DB::table('notification_texts')
            ->where('type', $type)
            ->where('language', $language !== '' ? $language : 'en')
            ->orderBy('times_used')
            ->orderByRaw('last_used is null desc')
            ->orderBy('last_used')
            ->first();

        // No line in that language: fall back to English before falling back
        // to the hard-coded sentence, so a missing translation does not become
        // a missing reminder.
        if ($row === null && $language !== 'en') {
            return $this->body($type, 'en');
        }

        if ($row === null) {
            return self::FALLBACK[$type] ?? self::FALLBACK[ReminderSubscription::HOURLY];
        }

        DB::table('notification_texts')->where('id', $row->id)->update([
            'times_used' => DB::raw('times_used + 1'),
            'last_used' => now(),
        ]);

        return (string) $row->description;
    }
}
