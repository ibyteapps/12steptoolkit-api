<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Delivered-and-read, per person per message. UNIQUE (account_id, comment_id). */
class CommentReceipt extends Model
{
    protected $table = 'comment_receipts';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    public function toLegacyRow(): array
    {
        return [
            'id' => (int) $this->id,
            'account_id' => (int) $this->account_id,
            'comment_id' => (int) $this->comment_id,
            'time_delivered' => (int) $this->time_delivered,
            'time_read' => (int) $this->time_read,
            'modified' => $this->getRawOriginal('modified'),
        ];
    }
}
