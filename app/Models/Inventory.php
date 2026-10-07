<?php

namespace App\Models;

/**
 * Step 4 and Step 10 inventories. `inventoryforstep` is 4 or 10; `invtype` is
 * 1 Resentment, 2 Fear, 3 Harms Done, 4 Sex Conduct.
 *
 * `shared` and `reviewed` both mean "the sponsor has seen it" and both exist:
 * `8/getcounts.php` counts `shared = 0`, `8/getcounts_sponsee.php` counts
 * `reviewed = 0`, and `19/get_sponsee_steps_data.php` uses `reviewed`. Nothing
 * here picks one — that is a product decision with a back-fill attached, and it
 * is recorded in `docs/OPEN_QUESTIONS.md` rather than settled by a port.
 */
class Inventory extends StepRecord
{
    protected $table = 'inventories';

    public static function writableColumns(): array
    {
        return [
            'inventoryforstep' => 'int', 'invtype' => 'int', 'invtitle' => 'string',
            'invdescription' => 'string', 'affectsmyint' => 'string', 'affectsmy' => 'string',
            'myfault' => 'string', 'shared' => 'int', 'shareddate' => 'string',
            'apologyowed' => 'int', 'apologydone' => 'int', 'apologydate' => 'string',
            'apologynotes' => 'string', 'timestamp' => 'string', 'tstamp' => 'int',
            'reviewed' => 'int',
        ];
    }

    public static function projection(): array
    {
        return [
            'inventoryforstep' => 'int', 'invtype' => 'int', 'invtitle' => 'string',
            'invdescription' => 'string', 'affectsmyint' => 'string', 'affectsmy' => 'string',
            'myfault' => 'string', 'shared' => 'int', 'shareddate' => 'string',
            'apologyowed' => 'int', 'apologydone' => 'int', 'apologydate' => 'string',
            'apologynotes' => 'string', 'created' => 'string', 'modified' => 'string',
            'timestamp' => 'string', 'tstamp' => 'int', 'reviewed' => 'int',
        ];
    }
}
