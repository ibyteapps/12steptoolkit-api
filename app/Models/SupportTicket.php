<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_member_at' => 'datetime',
            'last_staff_at' => 'datetime',
            'member_read_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }
}
