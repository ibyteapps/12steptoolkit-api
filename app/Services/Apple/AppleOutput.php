<?php

namespace App\Services\Apple;

use Illuminate\Http\Response;

/**
 * The exact bytes the v8 clients expect.
 *
 * iOS 1.6.6 does not parse these responses so much as sniff them. Success is
 * `outputStr.contains("success")` in roughly fifteen places
 * (`_Constants.swift:528, 1411, 1562, 2332`, `OTPVC.swift:383, 428`,
 * `UserDetailVC.swift:574`, …), which has two consequences this class exists to
 * respect:
 *
 *  * **a success string must contain the word.** `"success"` and `"sqlsuccess"`
 *    both match, and the old scripts used both, so each endpoint keeps whichever
 *    one it used.
 *  * **an error string must not contain it anywhere.** A PHP notice with the
 *    word in a stack trace was read as success by the old server; nothing here
 *    will emit one.
 *
 * What the old scripts also returned was **the executed SQL**: `"success " .
 * $sql`. The trailing space is kept because it is part of the literal the client
 * was built against; the statement is not, because a response that echoes a
 * query is a response that leaks a schema to anybody holding a secret printed
 * in a `test.html`.
 */
final class AppleOutput
{
    /** `text/html` with no charset was what PHP's default `Content-Type` gave. */
    private const TEXT = 'text/html; charset=UTF-8';

    public static function success(): Response
    {
        return self::text('success ');
    }

    public static function sqlSuccess(): Response
    {
        return self::text('sqlsuccess');
    }

    public static function error(): Response
    {
        return self::text('error');
    }

    public static function sqlError(): Response
    {
        return self::text('sqlerror');
    }

    public static function text(string $body, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => self::TEXT]);
    }

    /**
     * A JSON array of rows.
     *
     * The old `getlist.php` assembled this by hand — `echo '['`, then a
     * `json_encode($row)` per row, then `echo ','` — and the comma was only
     * emitted `if (json_last_error() == JSON_ERROR_NONE)`. Read that carefully:
     * a row that fails to encode prints **nothing**, and if the row that fails
     * is not the last one the comma for the row before it has already gone out.
     * A row failing anywhere except last produces `[{…},]`, which is not JSON.
     *
     * Nothing hits that today, and it is luck rather than design: every column
     * these queries select is text in a charset the connection converts, so
     * `json_encode` always succeeds. The commented-out `utf8_encode` and
     * `utf8ize` left in the original around the sponsor directory say plainly
     * that it did not always hold.
     *
     * `json_encode` once, over the whole array, with the flags that make
     * malformed bytes a *substituted character* rather than a failure. Either
     * every row arrives or the request fails; there is no shape in between.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function rows(array $rows): Response
    {
        $json = json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        // `json_encode` with SUBSTITUTE does not fail on bad bytes, so `false`
        // here would mean something structural — recursion, or a resource in a
        // row. An empty array is a wrong answer; no answer is an honest one.
        if ($json === false) {
            return self::text('error', 500);
        }

        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * The icon clamp, which is a product rule hiding in an output loop.
     *
     * `getlist.php:163` rewrites any `icon` above 40 to the **string** `"0"`
     * before encoding, on every row of every type that has the column. There
     * are 41 bundled avatars, 0–40, so a value outside that range would leave
     * the client with no image at all. The string rather than the integer is
     * what the old code produced and what the client's decoder was built
     * against.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function clampIcon(array $row): array
    {
        if (array_key_exists('icon', $row) && (int) $row['icon'] > 40) {
            $row['icon'] = '0';
        }

        return $row;
    }
}
