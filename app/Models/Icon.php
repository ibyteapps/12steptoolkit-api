<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded profile picture.
 *
 * `accounts.icon` is 1–40 for one of the bundled avatars and, above 40, an
 * `icons.id`. The iOS readers coerce anything above 40 to 0, so an Android
 * upload shows as "no avatar" there — which is a client bug, not a server one,
 * and worth knowing before anybody reports it as missing data.
 *
 * `image_url` holds a bare filename (`<id>.<ext>`), not a URL, despite the name.
 * The file lives outside the web root and is streamed by the application, which
 * is what makes "only people who may see this picture can fetch it"
 * enforceable at all.
 */
class Icon extends Model
{
    protected $table = 'icons';

    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    /** `accounts.icon` at or below this is a bundled avatar, not a row here. */
    public const BUILT_IN_MAX = 40;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function path(): string
    {
        return rtrim((string) config('toolkit.icons.path'), '/').'/'.basename((string) $this->image_url);
    }

    public function exists(): bool
    {
        return $this->image_url !== '' && $this->image_url !== null && is_file($this->path());
    }
}
