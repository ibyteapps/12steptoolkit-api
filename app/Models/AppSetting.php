<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single global configuration row, over the adopted `appsettings` table.
 *
 * Its column names are upper-case and underscore-separated, unlike every other
 * table in the schema, and `19/get_app_settings.php` is `SELECT * … LIMIT 1`
 * returning the row verbatim — so a column added here appears on the wire to
 * every client immediately. That is a feature the apps rely on (it is the only
 * remote-config mechanism that exists) and a hazard worth knowing about.
 *
 * The defaults below are the client's own, from the Flutter app's
 * `AppSetting` model, so an empty table still produces a working app rather
 * than zero-length text fields.
 */
class AppSetting extends Model
{
    protected $table = 'appsettings';

    public $timestamps = false;

    protected $guarded = ['*'];

    public const DEFAULTS = [
        'MAX_TITLE' => 30,
        'MAX_TITLE_PRO' => 200,
        'MAX_DESCRIPTION' => 200,
        'MAX_DESCRIPTION_PRO' => 500,
        'MAX_NOTES' => 500,
        'MAX_NOTES_PRO' => 5000,
        'MAX_MEETING_SEARCHES' => 5,
        'MAX_MEETING_SEARCHES_PRO' => 99,
        'MAX_GROUPS_FREE' => 0,
        'TIME_BETWEEN_ADS' => 45,
        'TAPS_BETWEEN_ADS' => 3,
        'TIME_BETWEEN_APP_OPEN' => 45,
        'SPONSOR_COUNT' => 0,
        'SALE_TITLE' => '',
        'SALE_START' => 0,
        'SALE_END' => 0,
    ];

    /** The row as the clients expect it, with the defaults filled in. */
    public static function row(): array
    {
        $row = static::query()->first()?->getAttributes() ?? [];

        return array_merge(self::DEFAULTS, array_filter($row, fn ($v) => $v !== null));
    }
}
