<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per successful sign-in: when, how, and from which platform.
 *
 * No IP address and no user agent. The console uses it to answer "has this
 * person really been using this account", which needs the shape of the history
 * and not the identity of the network they were on.
 */
class SignIn extends Model
{
    protected $table = 'sign_ins';

    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'method', 'platform', 'app_version', 'created_at'];

    protected function casts(): array
    {
        return ['account_id' => 'int', 'created_at' => 'datetime'];
    }
}
