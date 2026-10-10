<?php

use App\Models\Account;
use App\Models\Entitlement;
use App\Models\InstallSecret;
use App\Services\Billing\EntitlementService;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Support\Facades\DB;

use function Tests\Support\signAs;

/*
 | `add_order.php` writes a receipt and `revcat_is_subscribed.php` answers a
 | question. The important property of the first is what it does NOT do: a
 | row in `subscription_orders` is a client's word about a purchase, and if
 | that granted premium then the paywall would be a POST away.
 |
 | The important property of the second is who it will answer about. The live
 | script has its `db.php` include commented out — so no JWT, no HMAC — and
 | asks RevenueCat about whatever `account_id` the body carries. Account ids
 | are sequential.
 */

beforeEach(function () {
    $make = function (): Account {
        $a = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
        $a->save();

        return $a;
    };

    $this->account = $make();
    $this->other = $make();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];

    $this->record = fn (array $fields = []) => signAs($this, '/api/v1/android/19/add_order.php', array_merge([
        'account_id' => $this->account->id,
        'orderid' => 'GPA.1111-2222-3333-44444',
        'sku' => 'com.12stepapp.recoverybox.annual1',
        'tstamp' => time(),
        'months' => 12,
        'quantity' => 1,
        'price' => '39.99',
    ], $fields));

    $this->ask = fn (array $fields = []) => signAs($this, '/api/v1/android/19/revcat_is_subscribed.php', array_merge([
        'account_id' => $this->account->id,
    ], $fields));
});

// --- recording a receipt ----------------------------------------------------

it('records a receipt against the signed account', function () {
    ($this->record)()->assertOk()->assertJsonPath('status', true);

    $row = DB::table('subscription_orders')->first();

    expect((int) $row->accountid)->toBe((int) $this->account->id)
        ->and((string) $row->orderid)->toBe('GPA.1111-2222-3333-44444')
        ->and((int) $row->months)->toBe(12);
});

it('updates rather than duplicates when the same order is sent again', function () {
    ($this->record)()->assertOk();
    ($this->record)(['price' => '44.99'])->assertOk();

    expect(DB::table('subscription_orders')->count())->toBe(1)
        ->and((string) DB::table('subscription_orders')->value('price'))->toBe('44.99');
});

it('grants no access at all', function () {
    ($this->record)()->assertOk();

    // The row exists; the entitlement does not. Access comes from a receipt
    // this server verified with Apple or Google, a console grant, a gift, or
    // RevenueCat — never from a client saying it bought something.
    expect(app(EntitlementService::class)->isActive((int) $this->account->id))->toBeFalse()
        ->and((int) $this->account->fresh()->getRawOriginal('subscribed'))->toBe(0);
});

it('refuses a receipt filed against somebody else', function () {
    ($this->record)(['account_id' => $this->other->id])->assertStatus(403);

    expect(DB::table('subscription_orders')->count())->toBe(0);
});

it('refuses a receipt with no order id', function () {
    ($this->record)(['orderid' => ''])->assertStatus(400);

    expect(DB::table('subscription_orders')->count())->toBe(0);
});

it('reads the old spelling of the timestamp', function () {
    // Every old script reads `timestamp`; the new client sends `tstamp`.
    ($this->record)(['tstamp' => null, 'timestamp' => 1760000000])->assertOk();

    expect((int) DB::table('subscription_orders')->value('tstamp'))->toBe(1760000000);
});

// --- answering "am I subscribed?" ------------------------------------------

it('answers no for somebody with nothing', function () {
    ($this->ask)()->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('is_subscribed', false)
        ->assertJsonPath('response', false);
});

it('answers yes once something grants access', function () {
    Entitlement::query()->firstOrNew(['account_id' => $this->account->id])->forceFill([
        'is_active' => true,
        'state' => 'active',
        'source' => 'complimentary',
        'expires_at' => now()->addMonth(),
    ])->save();

    ($this->ask)()->assertOk()
        ->assertJsonPath('is_subscribed', true)
        ->assertJsonPath('response', true);
});

it('re-reads the date rather than trusting a stale row', function () {
    // An expiry is a date passing, not an event anybody sends, so a row left
    // active after its date must not keep answering yes.
    Entitlement::query()->firstOrNew(['account_id' => $this->account->id])->forceFill([
        'is_active' => true,
        'state' => 'active',
        'source' => 'apple',
        'expires_at' => now()->subDay(),
    ])->save();

    ($this->ask)()->assertOk()->assertJsonPath('response', false);
});

it('will not answer about anybody else', function () {
    ($this->ask)(['account_id' => $this->other->id])->assertStatus(403);
});

it('refuses an unsigned question, which the live script does not', function () {
    $this->post('/api/v1/android/19/revcat_is_subscribed.php', [
        'account_id' => $this->other->id,
    ])->assertStatus(401);
});
