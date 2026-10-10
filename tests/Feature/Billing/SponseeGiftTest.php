<?php

use App\Models\Account;
use App\Models\Entitlement;
use App\Models\InstallSecret;
use App\Models\Sponsor;
use App\Services\Billing\GiftOutcome;
use App\Services\Billing\SponseeGifts;
use App\Services\Legacy\LegacyJwt;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\DB;

use function Tests\Support\signAs;

/*
 | Gifting is the one purchase where the person who pays and the person who
 | gets the benefit are different people, which is what makes the live
 | version's missing authentication expensive: `giftsubscription.php` reads
 | `accountid` out of the POST body, so any stranger could spend any sponsor's
 | seats on any account id.
 |
 | These tests are written around the three things that can go wrong with
 | somebody else's money: spending seats that are not yours, spending the same
 | seat twice, and taking the money for months that never arrive.
 */

/** A sender that remembers what it was asked to send. */
class GiftRecordingSender implements PushSender
{
    /** @var array<int, array{tokens: array<int, string>, message: PushMessage}> */
    public array $sent = [];

    public function send(array $tokens, PushMessage $message): int
    {
        $this->sent[] = ['tokens' => $tokens, 'message' => $message];

        return count($tokens);
    }
}

beforeEach(function () {
    $this->sender = new GiftRecordingSender;
    $this->app->instance(PushSender::class, $this->sender);

    $make = function (array $overrides = []): Account {
        $a = Account::make()->forceFill(array_merge(['nickname' => ' ', 'created' => now()], $overrides));
        $a->save();

        return $a;
    };

    $this->sponsor = $make();
    $this->sponsee = $make(['fcm_token' => 'sponsee-device']);
    $this->stranger = $make();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->sponsor->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->sponsor->id)['access_token'];
    $this->account = $this->sponsor;

    Sponsor::query()->insert([
        'sponsorid' => $this->sponsor->id,
        'sponseeid' => $this->sponsee->id,
        'status' => Sponsor::ACCEPTED,
        'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);

    $this->gift = fn (array $fields = []) => signAs($this, '/api/v1/android/19/add_sponsee_order_and_gift.php', array_merge([
        'account_id' => $this->sponsor->id,
        'sponsee_id' => $this->sponsee->id,
        'orderid' => 'GPA.0001-0002-0003-00004',
        'sku' => 'com.12stepapp.recoverybox.annual_sponsee1',
        'tstamp' => time(),
        'months' => 12,
        'quantity' => 1,
        'price' => '24.99',
    ], $fields));

    $this->seats = fn (): int => DB::table('sponsee_order_users')->count();
});

// --- the gift itself --------------------------------------------------------

it('records the purchase, assigns one seat and tells the member', function () {
    ($this->gift)()->assertOk()->assertJsonPath('status', true);

    $order = DB::table('sponsee_orders')->first();
    expect((int) $order->accountid)->toBe((int) $this->sponsor->id)
        ->and((int) $order->months)->toBe(12)
        ->and((int) $order->quantity)->toBe(1);

    $seat = DB::table('sponsee_order_users')->first();
    expect((int) $seat->sponseeid)->toBe((int) $this->sponsee->id)
        ->and((int) $seat->sponsee_order_id)->toBe((int) $order->id)
        // R10: the term starts when the seat is assigned, not when it was
        // bought, so the clock is today's and not the purchase timestamp.
        ->and((int) $seat->tstamp)->toBeGreaterThan(time() - 5);

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0]['message']->table())->toBe('SUBSCRIPTION_GIFTED')
        ->and($this->sender->sent[0]['tokens'])->toBe(['sponsee-device']);
});

it('makes the sponsee premium immediately', function () {
    ($this->gift)()->assertOk();

    $entitlement = Entitlement::query()->find($this->sponsee->id);

    expect((bool) $entitlement->is_active)->toBeTrue()
        ->and($entitlement->source)->toBe('sponsee_gift')
        ->and($entitlement->expires_at->greaterThan(now()->addMonths(11)))->toBeTrue()
        // R4: a gift is bought outright, so nothing about it renews.
        ->and((bool) $entitlement->will_renew)->toBeFalse();

    // Written through to the column both old apps read, additively.
    expect((string) $this->sponsee->fresh()->getRawOriginal('subscribed'))->not->toBe('');
});

it('is idempotent: a retried call does not spend a second seat', function () {
    ($this->gift)()->assertOk();
    ($this->gift)()->assertOk()->assertJsonPath('response.outcome', GiftOutcome::AlreadyGifted->value);

    expect(DB::table('sponsee_orders')->count())->toBe(1)
        ->and(($this->seats)())->toBe(1)
        // The second call is a no-op, so the member is not told twice.
        ->and($this->sender->sent)->toHaveCount(1);
});

it('refuses a second member on a one-seat order', function () {
    Sponsor::query()->insert([
        'sponsorid' => $this->sponsor->id, 'sponseeid' => $this->stranger->id,
        'status' => Sponsor::ACCEPTED, 'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);

    ($this->gift)()->assertOk();
    ($this->gift)(['sponsee_id' => $this->stranger->id])->assertStatus(409);

    expect(($this->seats)())->toBe(1);
});

it('ignores a quantity the client asks for but did not buy', function () {
    ($this->gift)()->assertOk();

    Sponsor::query()->insert([
        'sponsorid' => $this->sponsor->id, 'sponseeid' => $this->stranger->id,
        'status' => Sponsor::ACCEPTED, 'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);

    // Same order id, a bigger quantity. The live script would have taken the
    // posted number as the seat limit and handed out a second seat.
    ($this->gift)(['sponsee_id' => $this->stranger->id, 'quantity' => 50])->assertStatus(409);

    expect(($this->seats)())->toBe(1)
        ->and((int) DB::table('sponsee_orders')->value('quantity'))->toBe(1);
});

// --- who may be gifted to --------------------------------------------------

it('refuses a member the caller is not connected to', function () {
    ($this->gift)(['sponsee_id' => $this->stranger->id])->assertStatus(403);

    expect(($this->seats)())->toBe(0);
});

it('allows a gift to a chat connection, which is what the Upgrade card offers', function () {
    Sponsor::query()->insert([
        'sponsorid' => $this->stranger->id, 'sponseeid' => $this->sponsor->id,
        'status' => Sponsor::CHAT_ACCEPTED, 'relationship_direction' => Sponsor::SPONSEE_TO_SPONSOR,
    ]);

    ($this->gift)(['sponsee_id' => $this->stranger->id])->assertOk();
});

it('refuses an account that has been erased', function () {
    $this->sponsee->forceFill(['email' => 'DELETED'])->save();

    ($this->gift)()->assertStatus(403);
});

it('refuses a body that names somebody else as the buyer', function () {
    ($this->gift)(['accountid' => $this->stranger->id, 'account_id' => null])->assertStatus(403);

    expect(($this->seats)())->toBe(0);
});

it('refuses an unsigned request', function () {
    $this->post('/api/v1/android/19/add_sponsee_order_and_gift.php', [
        'accountid' => $this->sponsor->id, 'sponsee_id' => $this->sponsee->id,
        'orderid' => 'x', 'sku' => 'annual_sponsee1', 'timestamp' => time(),
    ])->assertStatus(401);

    expect(($this->seats)())->toBe(0);
});

// --- the term --------------------------------------------------------------

it('reads a missing term off the SKU by its words, never its digits', function () {
    // `…annual_sponsee1` is a twelve-month gift whose id ends in a version
    // number. A regular expression that reads the first number out of the SKU
    // answers 1, and the sponsee loses eleven months.
    ($this->gift)(['months' => 0])->assertOk();

    expect((int) DB::table('sponsee_orders')->value('months'))->toBe(12);
});

it('reads a quarterly gift as three months', function () {
    ($this->gift)(['months' => 0, 'sku' => 'com.12stepapp.recoverybox.quarterly_sponsee1'])->assertOk();

    expect((int) DB::table('sponsee_orders')->value('months'))->toBe(3);
});

it('refuses a purchase whose term cannot be worked out at all', function () {
    // Better a refusal the sponsor can see than a row that silently grants
    // nothing: `get_sponsee_gift_expiry.php` requires `months > 0`.
    ($this->gift)(['months' => 0, 'sku' => 'some.unmapped.product'])->assertStatus(409);

    expect(DB::table('sponsee_orders')->count())->toBe(0)
        ->and(($this->seats)())->toBe(0);
});

// --- reading the expiry back ------------------------------------------------

it('answers the expiry endpoint with a bare array, as the clients parse it', function () {
    ($this->gift)()->assertOk();

    // Ask as the sponsee: their install, their token, their account. The
    // helper signs as whoever these four properties say.
    $this->secret = random_bytes(32);
    $this->deviceId = 'device-2';
    InstallSecret::query()->insert([
        'account_id' => $this->sponsee->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->sponsee->id)['access_token'];
    $this->account = $this->sponsee;

    $response = signAs($this, '/api/v1/android/19/get_sponsee_gift_expiry.php', [
        'account_id' => $this->sponsee->id,
    ]);

    $response->assertOk();
    $body = $response->json();

    expect($body)->toBeArray()->toHaveCount(1)
        // A string, which is what the old script's `(string)` cast produces
        // and what the client's `int.tryParse` expects to have to handle.
        ->and($body[0]['tstamp'])->toBeString()
        ->and((int) $body[0]['tstamp'])->toBeGreaterThan(now()->addMonths(11)->getTimestamp());
});

it('answers an empty array for somebody with no gift', function () {
    signAs($this, '/api/v1/android/19/get_sponsee_gift_expiry.php', [
        'account_id' => $this->sponsor->id,
    ])->assertOk()->assertExactJson([]);
});

it('will not tell you about anybody else', function () {
    signAs($this, '/api/v1/android/19/get_sponsee_gift_expiry.php', [
        'account_id' => $this->sponsee->id,
    ])->assertStatus(403);
});

// --- the service's own rules ------------------------------------------------

it('takes the longest of two gifts rather than the most recent', function () {
    $gifts = app(SponseeGifts::class);

    $long = DB::table('sponsee_orders')->insertGetId([
        'accountid' => $this->sponsor->id, 'orderid' => 'one', 'sku' => 'annual_sponsee1',
        'tstamp' => time(), 'months' => 12, 'quantity' => 1, 'price' => '',
    ]);
    $short = DB::table('sponsee_orders')->insertGetId([
        'accountid' => $this->sponsor->id, 'orderid' => 'two', 'sku' => 'quarterly_sponsee1',
        'tstamp' => time(), 'months' => 3, 'quantity' => 1, 'price' => '',
    ]);

    DB::table('sponsee_order_users')->insert([
        ['sponsee_order_id' => $long, 'sponseeid' => $this->sponsee->id, 'tstamp' => time()],
        ['sponsee_order_id' => $short, 'sponseeid' => $this->sponsee->id, 'tstamp' => time()],
    ]);

    // The live `ORDER BY sou.id DESC LIMIT 1` would answer with the three
    // months. Nothing may take away access another row still grants.
    expect($gifts->accessUntil((int) $this->sponsee->id)->greaterThan(now()->addMonths(11)))->toBeTrue();
});

it('grants nothing for a seat on an order with no term', function () {
    $orderId = DB::table('sponsee_orders')->insertGetId([
        'accountid' => $this->sponsor->id, 'orderid' => 'legacy-row', 'sku' => 'annual_sponsee1',
        'tstamp' => time(), 'months' => 0, 'quantity' => 1, 'price' => '',
    ]);
    DB::table('sponsee_order_users')->insert([
        'sponsee_order_id' => $orderId, 'sponseeid' => $this->sponsee->id, 'tstamp' => time(),
    ]);

    // `months = 0` grants nothing in production today, and this must not
    // start granting something: the term is resolved on the way in, never
    // guessed on the way out.
    expect(app(SponseeGifts::class)->accessUntil((int) $this->sponsee->id))->toBeNull();
});

it('counts what a sponsor has bought, used and has left', function () {
    ($this->gift)()->assertOk();

    expect(app(SponseeGifts::class)->purchases((int) $this->sponsor->id))->toBe([[
        'sku' => 'com.12stepapp.recoverybox.annual_sponsee1',
        'quantity' => 1,
        'used' => 1,
        'available' => 0,
    ]]);
});
