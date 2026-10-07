<?php

namespace App\Services\Legacy;

use Illuminate\Http\Request;

/**
 * The exact string the Android client signs.
 *
 * This is not a design: it is a transcription. Both shipped clients build it
 * already — the Kotlin app in `HmacInterceptor.kt:22-29` and the Flutter app in
 * `lib/core/network/signing.dart` — and the live server rebuilds it in
 * `19/auth_checker.php:50-54`:
 *
 * ```php
 * $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
 * $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
 * $query  = $_SERVER['QUERY_STRING'] ?? '';
 * $pathAndQuery = $path . ($query !== '' ? '?' . $query : '');
 * $canonical = implode("\n", [$method, $pathAndQuery, $calcBodySha, (string) $ts, $nonce]);
 * ```
 *
 * Five lines joined with `\n` and no trailing newline:
 *
 *     METHOD
 *     PATH[?QUERY]
 *     SHA256_HEX_LOWER(body)
 *     TIMESTAMP
 *     NONCE
 *
 * Three details decide whether a real request verifies, and all three are the
 * kind that look like tidying up:
 *
 *  * **the query is included only when it is non-empty**, and without the `?`
 *    when there is none — `QUERY_STRING` is `''`, not null, for `/x.php?`;
 *  * **the path is the raw, still-encoded path.** `parse_url` does not decode,
 *    and neither may this. Laravel's `$request->path()` is decoded and is
 *    missing its leading slash, so it is not used;
 *  * **the timestamp is seconds**, as a plain decimal string.
 *
 * Nothing in this class touches the database or the container, so the whole of
 * it is covered by `tests/Unit/CanonicalRequestTest.php`.
 */
final class CanonicalRequest
{
    /**
     * @param  string  $method  the HTTP method, upper-cased by this function
     * @param  string  $requestUri  the raw `REQUEST_URI`, path and query, still encoded
     * @param  string  $bodySha256Hex  lower-case hex SHA-256 of the raw body
     */
    public static function build(
        string $method,
        string $requestUri,
        string $bodySha256Hex,
        int $epochSeconds,
        string $nonce,
    ): string {
        return implode("\n", [
            strtoupper($method),
            self::pathAndQuery($requestUri),
            strtolower($bodySha256Hex),
            (string) $epochSeconds,
            $nonce,
        ]);
    }

    /** Builds the same string from a Laravel request. */
    public static function fromRequest(Request $request, string $bodySha256Hex, int $epochSeconds, string $nonce): string
    {
        return self::build(
            $request->getMethod(),
            (string) $request->server->get('REQUEST_URI', '/'),
            $bodySha256Hex,
            $epochSeconds,
            $nonce,
        );
    }

    /**
     * `parse_url($uri, PHP_URL_PATH)` plus `?QUERY_STRING` when non-empty.
     *
     * `parse_url` returns null for a URI it cannot make sense of; the old code
     * falls back to `/` and so does this.
     */
    public static function pathAndQuery(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $query = parse_url($requestUri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : '';

        return $query !== '' ? $path.'?'.$query : $path;
    }

    /** The hash both sides put on line three. */
    public static function bodyHash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    /**
     * The signature, base64 of the raw HMAC — not hex.
     *
     * `auth_checker.php:52` is `base64_encode(hash_hmac('sha256', $canonical,
     * $secretBin, true))`, and the `true` is what makes it raw.
     */
    public static function sign(string $canonical, string $secretBinary): string
    {
        return base64_encode(hash_hmac('sha256', $canonical, $secretBinary, true));
    }
}
