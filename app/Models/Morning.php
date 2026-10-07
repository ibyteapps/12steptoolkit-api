<?php

namespace App\Models;

/**
 * The on-awakening inventory: a mood, four yes/no questions and a note.
 *
 * `icons` is a CSV of 1-based mood indices. `q2`..`q5` are INTEGER columns and
 * they **default to 1** in the Android entity, so a row written by an older
 * build that never answered a question reads as "yes" — which is wrong and is
 * what is stored. Nothing here rewrites it.
 */
class Morning extends StepRecord
{
    protected $table = 'mornings';

    public static function writableColumns(): array
    {
        return [
            'icons' => 'string', 'q2' => 'int', 'q3' => 'int', 'q4' => 'int', 'q5' => 'int',
            'q6_notes' => 'string', 'tstamp' => 'int',
        ];
    }

    public static function projection(): array
    {
        return [
            'icons' => 'string', 'q2' => 'int', 'q3' => 'int', 'q4' => 'int', 'q5' => 'int',
            'q6_notes' => 'string', 'tstamp' => 'int', 'created' => 'string', 'modified' => 'string',
        ];
    }
}
