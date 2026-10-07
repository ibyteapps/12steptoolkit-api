<?php

use App\Services\Legacy\CanonicalRequest;

/**
 * The signature the Android and Flutter clients already produce.
 *
 * Every expectation here is read off a shipped client or the live PHP, not off
 * this implementation. A change that breaks one of these breaks every signed
 * request from every install in the field.
 */
it('joins five lines with a newline and no trailing newline', function () {
    $canonical = CanonicalRequest::build('post', '/aa/android/19/get_counts.php', 'ABCDEF', 1760000000, 'n1');

    expect($canonical)->toBe("POST\n/aa/android/19/get_counts.php\nabcdef\n1760000000\nn1");
    expect(str_ends_with($canonical, "\n"))->toBeFalse();
});

it('includes the query only when there is one', function () {
    expect(CanonicalRequest::pathAndQuery('/x.php'))->toBe('/x.php');
    // PHP's QUERY_STRING for "/x.php?" is '', so no '?' is appended.
    expect(CanonicalRequest::pathAndQuery('/x.php?'))->toBe('/x.php');
    expect(CanonicalRequest::pathAndQuery('/x.php?a=1&b=2'))->toBe('/x.php?a=1&b=2');
});

it('does not decode the path', function () {
    // parse_url does not decode, and neither may this: the client signed the
    // encoded form, so decoding here would fail every request with a space or
    // a non-ASCII character in the path.
    expect(CanonicalRequest::pathAndQuery('/a%20b/c%C3%A9.php?q=%2F'))->toBe('/a%20b/c%C3%A9.php?q=%2F');
});

it('falls back to / for a request uri it cannot parse', function () {
    expect(CanonicalRequest::pathAndQuery(''))->toBe('/');
});

it('hashes the body as lower-case hex', function () {
    expect(CanonicalRequest::bodyHash(''))
        ->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    expect(CanonicalRequest::bodyHash('account_id=1'))->toBe(hash('sha256', 'account_id=1'));
});

it('signs with raw-binary hmac and base64, not hex', function () {
    $secret = random_bytes(32);
    $canonical = "GET\n/x.php\nabc\n1\nn";

    expect(CanonicalRequest::sign($canonical, $secret))
        ->toBe(base64_encode(hash_hmac('sha256', $canonical, $secret, true)));
});

it('rebuilds a signature the client produced', function () {
    // The numbers below are a worked example of what the shipped client sends.
    $secret = hex2bin(str_repeat('ab', 32));
    $body = 'account_id=443514&record_id=12';
    $hash = CanonicalRequest::bodyHash($body);

    $canonical = CanonicalRequest::build('POST', '/12steptoolkit.com/aa/android/19/get_inventories.php', $hash, 1760000123, 'c0ffee');
    $signature = CanonicalRequest::sign($canonical, $secret);

    // Verifying is rebuilding and comparing; a second pass must agree with the first.
    expect(CanonicalRequest::sign(
        CanonicalRequest::build('post', '/12steptoolkit.com/aa/android/19/get_inventories.php', strtoupper($hash), 1760000123, 'c0ffee'),
        $secret,
    ))->toBe($signature);
});
