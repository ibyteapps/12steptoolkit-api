<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Somebody who may sign in to /console. A separate table and guard from the app's
 * users: nobody becomes staff by signing in to the app. Created with
 * `php artisan console:user`; there is no registration page.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $active
 */
class ConsoleUser extends Authenticatable
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    /** No "remember me": a console session ends when it ends. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'last_login_at' => 'datetime'];
    }
}
