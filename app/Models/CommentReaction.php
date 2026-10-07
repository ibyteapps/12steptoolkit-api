<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An emoji against a message. UNIQUE (comment_id, account_id, reaction). */
class CommentReaction extends Model
{
    protected $table = 'comment_reactions';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    public function toLegacyRow(): array
    {
        return [
            'reaction_id' => (int) $this->id,
            'comment_id' => (int) $this->comment_id,
            'account_id' => (int) $this->account_id,
            'reaction' => (string) $this->reaction,
            'is_deleted' => (int) $this->is_deleted,
            'modified' => $this->getRawOriginal('modified'),
        ];
    }
}
