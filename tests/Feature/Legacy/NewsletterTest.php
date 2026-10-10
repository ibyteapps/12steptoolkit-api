<?php

use App\Models\Account;
use App\Models\InstallSecret;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Support\Facades\Http;

use function Tests\Support\signAs;

/*
 | Both legacy versions take the address to subscribe out of the POST body
 | with no authentication — `aa/web/1/newsletter_subscribe.php` reads `email`
 | and `accountid`, `8/newsletter_subscribe.php` reads `sEmail` and hands it
 | to Sendy with a key written into the file. Either will put somebody else's
 | address on an A.A. mailing list.
 |
 | So the tests that matter here are the ones about whose address it is.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'legacy.apple.enabled' => true,
        'legacy.apple.server_secret' => 'a-test-secret',
        'services.sendy.url' => 'https://sendy.example.com',
        'services.sendy.api_key' => 'test-key',
        'services.sendy.lists' => ['toolkit' => 'LIST123'],
        'services.sendy.default_list' => 'toolkit',
    ]);

    $this->account = Account::make()->forceFill([
        'nickname' => 'Tom', 'email' => 'member@example.com', 'created' => now(),
    ]);
    $this->account->save();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];
});

// --- v19 --------------------------------------------------------------------

it('subscribes the signed account and records it', function () {
    Http::fake(['sendy.example.com/subscribe' => Http::response('1')]);

    signAs($this, '/api/v1/android/19/newsletter_subscribe.php', ['account_id' => $this->account->id])
        ->assertOk()
        ->assertJsonPath('response.newsletter_subscribed', 1);

    expect((int) $this->account->fresh()->newsletter_subscribed)->toBe(1);

    Http::assertSent(fn ($request) => $request['email'] === 'member@example.com'
        && $request['list'] === 'LIST123'
        && $request['api_key'] === 'test-key');
});

it('has no address parameter to abuse', function () {
    Http::fake(['sendy.example.com/subscribe' => Http::response('1')]);

    // Somebody else's address in the body changes nothing: there is no field
    // for it, and the account's own address is what is sent.
    signAs($this, '/api/v1/android/19/newsletter_subscribe.php', [
        'account_id' => $this->account->id,
        'email' => 'someone.else@example.com',
        'sEmail' => 'someone.else@example.com',
    ])->assertOk();

    Http::assertSent(fn ($request) => $request['email'] === 'member@example.com');
});

it('treats "already subscribed" as a success', function () {
    Http::fake(['sendy.example.com/subscribe' => Http::response('Already subscribed.')]);

    signAs($this, '/api/v1/android/19/newsletter_subscribe.php', [])->assertOk();

    expect((int) $this->account->fresh()->newsletter_subscribed)->toBe(1);
});

it('does not claim success when the list refuses', function () {
    Http::fake(['sendy.example.com/subscribe' => Http::response('Invalid list ID.')]);

    signAs($this, '/api/v1/android/19/newsletter_subscribe.php', [])->assertStatus(502);

    // The column is what the apps read to decide whether to nag. Setting it
    // on a failure means somebody is told they subscribed and never hears
    // anything.
    expect((int) $this->account->fresh()->newsletter_subscribed)->toBe(0);
});

it('says so plainly when there is no mailing list configured', function () {
    config(['services.sendy.api_key' => '']);

    signAs($this, '/api/v1/android/19/newsletter_subscribe.php', [])->assertStatus(503);

    expect((int) $this->account->fresh()->newsletter_subscribed)->toBe(0);
});

it('refuses an account with no address', function () {
    $this->account->forceFill(['email' => ''])->save();

    signAs($this, '/api/v1/android/19/newsletter_subscribe.php', [])->assertStatus(422);
});

it('answers the status from Sendy', function () {
    Http::fake(['sendy.example.com/api/subscribers/subscription-status.php' => Http::response('Unconfirmed')]);

    signAs($this, '/api/v1/android/19/newsletter_status.php', [])
        ->assertOk()
        ->assertJsonPath('response.status', 'notconfirmed');
});

it('falls back to what this server knows when Sendy cannot be asked', function () {
    Http::fake(['sendy.example.com/api/subscribers/subscription-status.php' => Http::response('', 500)]);

    $this->account->forceFill(['newsletter_subscribed' => 1])->save();

    // "Could not ask" is not "no".
    signAs($this, '/api/v1/android/19/newsletter_status.php', [])
        ->assertOk()
        ->assertJsonPath('response.status', 'subscribed');
});

// --- v8, which the old iOS build calls --------------------------------------

it('answers the Apple status endpoint with one of its three bare words', function () {
    Http::fake(['sendy.example.com/api/subscribers/subscription-status.php' => Http::response('Subscribed')]);

    $this->post('/api/v1/apple/8/getnewslettersubscribed.php', [
        'email' => 'member@example.com',
        'list' => '5',
        'serversecret' => config('legacy.apple.server_secret'),
    ])->assertOk()->assertSee('subscribed');
});

it('will not tell the Apple endpoint about an address that is not ours', function () {

    // The shared secret is printed in every binary, so the most this may do
    // with an address it is handed is act on one that already exists here.
    // No Sendy call is made at all — `preventStrayRequests` would fail the
    // test if one were.
    $this->post('/api/v1/apple/8/getnewslettersubscribed.php', [
        'email' => 'a.stranger@example.com',
        'list' => '5',
        'serversecret' => config('legacy.apple.server_secret'),
    ])->assertOk()->assertSee('notsubscribed');
});

it('subscribes through the Apple endpoint only for an address we hold', function () {
    Http::fake(['sendy.example.com/subscribe' => Http::response('1')]);

    $this->post('/api/v1/apple/8/newsletter_subscribe.php', [
        'sEmail' => 'a.stranger@example.com',
        'serversecret' => config('legacy.apple.server_secret'),
    ])->assertOk()->assertSee('error');

    $this->post('/api/v1/apple/8/newsletter_subscribe.php', [
        'sEmail' => 'member@example.com',
        'serversecret' => config('legacy.apple.server_secret'),
    ])->assertOk();

    expect((int) $this->account->fresh()->newsletter_subscribed)->toBe(1);
});
