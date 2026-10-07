<?php

namespace App\Services\Legacy;

use Illuminate\Http\JsonResponse;

/**
 * The v19 response shape, reproduced exactly.
 *
 * `{status: bool, message: string, response: <T>}`, with two documented
 * exceptions that are **not** tidied up here because clients parse them:
 *
 *  * `get_app_settings.php` answers under `data`, not `response`;
 *  * `mark_as_reviewed.php` has no envelope at all.
 *
 * The other habit that has to be preserved is stringification: every
 * `*_add_update.php` runs its response values through `strval()`, so the client
 * models expect `"1"` and not `1`. {@see strings} is how that is done, in one
 * place, rather than by remembering to quote things.
 */
final class LegacyEnvelope
{
    public static function ok(mixed $response = null, string $message = 'ok', int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'response' => $response,
        ], $status);
    }

    /**
     * The envelope, plus fields **beside** `response` rather than inside it.
     *
     * `comment_add_update.php` returns `comment_id` at the top level as well as
     * within `response`, and the Kotlin model reads the top-level one. There is
     * no tidy way to describe that, so it gets its own method rather than being
     * hand-assembled at the call site and quietly diverging.
     */
    public static function okWith(array $alongside, mixed $response = null, string $message = 'ok'): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
        ] + $alongside + ['response' => $response]);
    }

    public static function fail(string $message, int $status = 400, mixed $response = null): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'response' => $response,
        ], $status);
    }

    /**
     * Casts every scalar in an array to a string, as `strval()` does on the way
     * out of the old scripts. Nested arrays are walked; nulls become `''`,
     * which is what `strval(null)` gives.
     */
    public static function strings(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = is_array($value) ? self::strings($value) : (string) $value;
        }

        return $out;
    }
}
