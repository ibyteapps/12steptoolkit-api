<?php

namespace App\Models;

/** Identical in shape to a journal entry, different table. */
class Gratitude extends Journal
{
    protected $table = 'gratitudes';
}
