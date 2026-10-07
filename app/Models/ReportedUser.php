<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A report, for the console to deal with.
 *
 * The old system's entire moderation surface was `19/admin/reported.php`: an
 * unauthenticated HTML page that printed this table — the reporter's id, the
 * reported person's id and the free-text reason — to anybody who found the URL.
 */
class ReportedUser extends Model
{
    protected $table = 'reported_users';

    protected $guarded = ['*'];

    public const PENDING = 'pending';
}
