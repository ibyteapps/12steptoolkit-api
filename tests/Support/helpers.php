<?php

namespace Tests\Support;

use App\Services\Legacy\CanonicalRequest;
use Illuminate\Testing\TestResponse;

/**
 * Posts a form-encoded request signed the way the shipped clients sign it.
 *
 * The test is the client here: if this helper and `HmacVerifier` ever agree
 * only with each other, the suite is green and the field is broken. So it
 * builds the canonical string with the same function the production code uses,
 * and `tests/Unit/CanonicalRequestTest.php` is what holds *that* function to
 * the shape read off `19/auth_checker.php` and both clients.
 */
function signAs(object $test, string $path, array $fields = [], array $overrides = []): TestResponse
{
    $body = http_build_query($fields);
    $hash = CanonicalRequest::bodyHash($body);
    $ts = $overrides['ts'] ?? time();
    $nonce = $overrides['nonce'] ?? bin2hex(random_bytes(8));

    $signature = CanonicalRequest::sign(
        CanonicalRequest::build('POST', $path, $hash, $ts, $nonce),
        $overrides['secret'] ?? $test->secret,
    );

    // The fields are passed as parameters *and* as the raw body: that is what a
    // real request looks like once PHP has parsed an
    // `application/x-www-form-urlencoded` body into `$_POST`, and the signature
    // is over the body, not the parsed array.
    return $test->call('POST', $path, $fields, [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_AUTHORIZATION' => 'Bearer '.($overrides['token'] ?? $test->token),
        'HTTP_X_ACCOUNT_ID' => (string) ($overrides['accountId'] ?? $test->account->id),
        'HTTP_X_DEVICE_ID' => $overrides['deviceId'] ?? $test->deviceId,
        'HTTP_X_TIMESTAMP' => (string) $ts,
        'HTTP_X_NONCE' => $nonce,
        'HTTP_X_BODY_SHA256' => $hash,
        'HTTP_X_SIGNATURE' => $signature,
    ], $body);
}
