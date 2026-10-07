<?php

namespace App\Models;

/**
 * The Step 11 nightly inventory: twelve questions, eleven switches.
 *
 * ## `sw8`
 *
 * Both APIs write `sw1..sw7, sw9..sw12` and omit `sw8` from every INSERT and
 * UPDATE. Nobody has confirmed whether the column exists in MySQL — there is no
 * schema dump anywhere, and the security patch set says in terms that deploying
 * an `sw8` fix when the column is absent makes **every Step 11 save fail** with
 * `Unknown column 'sw8'`.
 *
 * So this application does what both clients already do: it does not write
 * `sw8`, and it does not read it. Question 8 is text-only on both platforms
 * anyway (`NightsFragment.kt:356`, `Step11VC.swift:91`), so nothing is lost.
 * `docs/OPEN_QUESTIONS.md` carries the one-line SQL that settles it.
 *
 * ## `desc*` versus `ans*`
 *
 * Android posts `desc1..desc12`; iOS posts `ans1..ans12` into the same columns.
 * The Apple controller maps them; nothing downstream knows there were two names.
 */
class Night extends StepRecord
{
    protected $table = 'nights';

    /** The eleven switch columns, in order. `sw8` is deliberately absent. */
    public const SWITCHES = ['sw1', 'sw2', 'sw3', 'sw4', 'sw5', 'sw6', 'sw7', 'sw9', 'sw10', 'sw11', 'sw12'];

    /** The twelve answer columns. All twelve exist; the numbering is not off. */
    public const DESCRIPTIONS = [
        'desc1', 'desc2', 'desc3', 'desc4', 'desc5', 'desc6',
        'desc7', 'desc8', 'desc9', 'desc10', 'desc11', 'desc12',
    ];

    public static function writableColumns(): array
    {
        $columns = [];
        foreach (self::SWITCHES as $sw) {
            // Strings, not booleans: the column holds 'Yes' or 'No'.
            $columns[$sw] = 'switch';
        }
        foreach (self::DESCRIPTIONS as $desc) {
            $columns[$desc] = 'string';
        }

        return $columns + [
            'timestamp' => 'string', 'thedate' => 'string', 'tstamp' => 'int',
            'tdate' => 'int', 'reviewed' => 'int', 'for_date' => 'int',
        ];
    }

    /**
     * `19/get_nights.php` is `SELECT *` with `id` renamed, so the response is
     * the raw row. That is reproduced by listing the columns explicitly instead,
     * because "whatever is in the table" is not a contract — a column added for
     * the console would otherwise appear on the wire to every client.
     */
    public static function projection(): array
    {
        $p = [];
        foreach (self::SWITCHES as $sw) {
            $p[$sw] = 'string';
        }
        foreach (self::DESCRIPTIONS as $desc) {
            $p[$desc] = 'string';
        }

        return $p + [
            'timestamp' => 'string', 'thedate' => 'string', 'tstamp' => 'int',
            'tdate' => 'int', 'reviewed' => 'int', 'for_date' => 'int',
            'created' => 'string', 'modified' => 'string',
        ];
    }

    /** `'Yes'` or `'No'`, defaulting to `'No'` exactly as the old code does. */
    public static function normaliseSwitch(mixed $value): string
    {
        if (is_string($value) && strcasecmp(trim($value), 'Yes') === 0) {
            return 'Yes';
        }
        if ($value === 1 || $value === '1' || $value === true) {
            return 'Yes';
        }

        return 'No';
    }
}
