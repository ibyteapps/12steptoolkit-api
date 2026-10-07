<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A starred message. UNIQUE (account_id, comment_id), so starring is an upsert. */
class CommentStar extends Model
{
    protected $table = 'comment_stars';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    public function toLegacyRow(): array
    {
        return [
            'id' => (int) $this->id,
            'comment_id' => (int) $this->comment_id,
            'account_id' => (int) $this->account_id,
            'is_deleted' => (int) $this->is_deleted,
            'modified' => $this->getRawOriginal('modified'),
        ];
    }
}
