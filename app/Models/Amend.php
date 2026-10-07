<?php

namespace App\Models;

/** Steps 8 and 9. */
class Amend extends StepRecord
{
    protected $table = 'amends';

    public static function writableColumns(): array
    {
        return [
            'amendstitle' => 'string', 'amendsfor' => 'string', 'amendsdone' => 'int',
            'amendsdate' => 'string', 'amendsnotes' => 'string', 'timestamp' => 'string',
            'tstamp' => 'int', 'reviewed' => 'int',
        ];
    }

    public static function projection(): array
    {
        return [
            'amendstitle' => 'string', 'amendsfor' => 'string', 'amendsdone' => 'int',
            'amendsdate' => 'string', 'timestamp' => 'string', 'amendsnotes' => 'string',
            'tstamp' => 'int', 'reviewed' => 'int', 'created' => 'string', 'modified' => 'string',
        ];
    }
}
