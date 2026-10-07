<?php

namespace App\Models;

/**
 * A journal entry. `description` is required and the server refuses an empty
 * one — `journal_add_update.php:16` does too, which is why the Flutter client
 * refuses it at the keyboard rather than letting the row sit in an outbox being
 * rejected for ever.
 *
 * `timestamp` holds a human-readable English date string
 * (`date('F j, Y h:i A l')`). It is written because the old clients display it;
 * `tstamp` is the one anything sorts by.
 */
class Journal extends StepRecord
{
    protected $table = 'journals';

    public static function writableColumns(): array
    {
        return ['description' => 'string', 'timestamp' => 'string', 'tstamp' => 'int'];
    }

    public static function projection(): array
    {
        return [
            'description' => 'string', 'created' => 'string',
            'modified' => 'string', 'tstamp' => 'string',
        ];
    }
}
