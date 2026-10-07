<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A value switched from the console (see App\Support\Settings). */
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
