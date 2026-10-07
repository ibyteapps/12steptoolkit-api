<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Account;
use App\Models\Icon;
use App\Models\Sponsor;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile pictures.
 *
 * ## The upload hole this replaces
 *
 * `upload_icon.php` decides two things from data the client controls, and
 * neither is checked against the file:
 *
 *  * **the type**, from `$_FILES['image']['type']` — a request header. Sending
 *    `image/png` is enough to pass the allow-list whatever the bytes are;
 *  * **the extension**, from `pathinfo($file['name'], PATHINFO_EXTENSION)` —
 *    the client's own filename. So `evil.php` arrives and is saved as
 *    `<id>.php`.
 *
 * There is no `getimagesize`, no size limit, and no check that the thing is an
 * image at all. What stops that being remote code execution is one accident of
 * deployment: `ICONS_PATH` points outside the web root, so the file is not
 * reachable by URL. That is a thin thing to be relying on, and it is the sort of
 * thing a future "serve icons directly from nginx for speed" change would
 * quietly remove.
 *
 * Here the type is read from the **bytes**, the extension is derived from that
 * rather than from anything the client said, the size is capped, and the
 * filename the client sent is never used for anything.
 *
 * ## Why uploading is the one endpoint without a signature
 *
 * The old scripts set both `REQUIRE_AUTH` and `REQUIRE_HMAC` to false here, and
 * only one of those was forced. PHP cannot read the raw body of a
 * `multipart/form-data` request — it consumes it into `$_POST` and `$_FILES`
 * and leaves `php://input` empty — while the client's `HmacInterceptor` hashes
 * the encoded body. The two can never agree, so the signature was impossible.
 * Dropping the token as well was not impossible, and made this an
 * unauthenticated file write for any account id. The token is required here;
 * the signature genuinely cannot be.
 *
 * ## And it is streamed, not served
 *
 * The file stays outside the web root and this application reads it out, which
 * is what makes "only people who may see this picture can fetch it" something
 * that can actually be enforced. A profile picture in a recovery app is not
 * public.
 */
class IconController extends Controller
{
    /** `upload_icon.php` is routed separately: it cannot be signed. */
    public const ENDPOINTS = [
        'delete_icon.php' => 'destroy',
    ];

    /** Detected type => the extension it is stored with. The allow-list, by bytes. */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function upload(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);
        $file = $request->file('image');

        if ($file === null || ! $file->isValid()) {
            return LegacyEnvelope::fail('No image uploaded', 400);
        }

        $max = (int) config('toolkit.icons.max_bytes');
        if ($file->getSize() > $max) {
            return LegacyEnvelope::fail('That picture is too large', 413);
        }

        // From the bytes. `getimagesize` returns false for anything that is not
        // an image it recognises, which is the check the old script has not got.
        $info = @getimagesize($file->getRealPath());
        $type = is_array($info) ? ($info[2] ?? null) : null;

        if ($type === null || ! array_key_exists($type, self::TYPES)) {
            return LegacyEnvelope::fail('That file is not a JPEG, PNG or WebP image', 415);
        }

        $extension = self::TYPES[$type];
        $directory = rtrim((string) config('toolkit.icons.path'), '/');

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            return LegacyEnvelope::fail('Server cannot store pictures', 500);
        }

        return DB::transaction(function () use ($account, $file, $extension, $directory): JsonResponse {
            // Clear the old one first, files included. The old script deletes
            // the old file using the *new* extension against the old id, so a
            // JPEG replaced by a PNG leaves the JPEG behind for ever.
            $this->removeExisting((int) $account->id);

            $icon = new Icon;
            $icon->forceFill(['account_id' => $account->id, 'image_url' => ''])->save();

            $name = $icon->id.'.'.$extension;

            // The client's filename is never used, here or anywhere.
            if (! $file->move($directory, $name)) {
                return LegacyEnvelope::fail('Failed to save uploaded image.', 500);
            }

            @chmod($directory.'/'.$name, 0644);

            $icon->forceFill(['image_url' => $name])->save();
            $account->forceFill(['icon' => $icon->id])->save();

            return LegacyEnvelope::ok(LegacyEnvelope::strings([
                'icon_id' => $icon->id,
                'image_url' => $name,
                'account_id' => $account->id,
            ]), 'Icon uploaded');
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);

        $this->removeExisting((int) $account->id);
        $account->forceFill(['icon' => 0])->save();

        return LegacyEnvelope::ok(['account_id' => (int) $account->id], 'Icon deleted');
    }

    /**
     * `GET /api/v1/android/19/icon/{account}` — stream somebody's picture.
     *
     * Not a v19 endpoint: the old apps build a URL to a static path. It exists
     * because the new client needs somewhere to fetch from that is not a public
     * directory, and because it is the only arrangement in which the rule below
     * can be applied at all.
     *
     * You may see a picture if it is yours, or if you have a live relationship
     * with that person, or if you share a thread. A recovery app's member
     * photographs are not a public directory listing.
     */
    public function show(Request $request, int $accountId): Response
    {
        $me = (int) $this->account($request)->id;

        if ($me !== $accountId && ! $this->maySee($me, $accountId)) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $icon = Icon::query()->where('account_id', $accountId)->latest('id')->first();

        if ($icon === null || ! $icon->exists()) {
            return LegacyEnvelope::fail('Not found', 404);
        }

        $response = response()->file($icon->path());

        /*
         | Private, and said through Symfony's own API rather than as a header
         | string: `BinaryFileResponse` normalises `Cache-Control` while it
         | prepares itself, and a header set by hand comes back out as
         | `public`. Which would mean a shared cache could hand one member's
         | photograph to the next person who asked for that URL — the exact
         | thing streaming these files instead of serving them is meant to
         | prevent.
         */
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->setAutoLastModified();

        return $response;
    }

    // ------------------------------------------------------------- plumbing

    private function removeExisting(int $accountId): void
    {
        foreach (Icon::query()->where('account_id', $accountId)->get() as $old) {
            if ($old->exists()) {
                @unlink($old->path());
            }

            $old->delete();
        }
    }

    /** A live relationship, or a thread in common. */
    private function maySee(int $me, int $them): bool
    {
        $related = Sponsor::query()
            ->involving($me)
            ->where(fn ($q) => $q->where('sponsorid', $them)->orWhere('sponseeid', $them))
            ->whereIn('status', Sponsor::VISIBLE)
            ->exists();

        if ($related) {
            return true;
        }

        return DB::table('comment_thread_subscribers as a')
            ->join('comment_thread_subscribers as b', 'a.thread_id', '=', 'b.thread_id')
            ->where('a.account_id', $me)
            ->where('b.account_id', $them)
            ->where('a.is_deleted', 0)
            ->where('b.is_deleted', 0)
            ->exists();
    }
}
