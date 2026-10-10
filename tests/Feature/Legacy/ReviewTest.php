<?php

use App\Models\Account;
use App\Models\Amend;
use App\Models\InstallSecret;
use App\Models\Inventory;
use App\Models\Night;
use App\Models\Sponsor;
use App\Services\Legacy\LegacyJwt;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;

use function Tests\Support\signAs;

/*
 | `18/reviewed.php` is
 |
 |     UPDATE $tablename SET reviewed=$reviewed WHERE id='$id'
 |
 | — no account, no sponsorship check, and the new value interpolated into the
 | statement. `19/mark_as_reviewed.php` binds the value and keeps the rest:
 | still `WHERE id = ?` alone. Any id marks any record reviewed for any
 | member. These tests are mostly about the cases that would have allowed.
 |
 | They also pin the contract, which is not this application's to choose:
 | the script name, the four `item_type` values, the field spellings and the
 | envelope-less `{success: 0|1}` body are what `sponsorship_repository.dart`
 | sends and reads.
 */

beforeEach(function () {
    $make = function (): Account {
        $a = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
        $a->save();

        return $a;
    };

    $this->sponsor = $make();
    $this->sponsee = $make();
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
        // Who asked. `sponsorid` is the sponsor either way — see
        // SponsorController::mayAccept() — so this must not change the answer,
        // which the test below pins.
        'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);

    $this->inventory = Inventory::make()->forceFill([
        'accountid' => $this->sponsee->id, 'inventoryforstep' => 4,
        'invtitle' => 'A resentment', 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $this->inventory->save();

    $this->strangers = Inventory::make()->forceFill([
        'accountid' => $this->stranger->id, 'inventoryforstep' => 4,
        'invtitle' => 'Not theirs to read', 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $this->strangers->save();
});

$review = fn (object $t, array $fields) => signAs($t, '/api/v1/android/19/mark_as_reviewed.php', $fields);

it('lets a sponsor mark a sponsee\'s inventory reviewed', function () use ($review) {
    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk();

    expect((int) $this->inventory->fresh()->getRawOriginal('reviewed'))->toBe(1);
});

it('refuses a record belonging to somebody they do not sponsor', function () use ($review) {
    $review($this, [
        'item_type' => 4, 'record_id' => $this->strangers->id,
        'friend_id' => $this->stranger->id, 'reviewed' => 1,
    ])->assertForbidden();

    expect((int) $this->strangers->fresh()->getRawOriginal('reviewed'))->toBe(0);
});

/*
 | The shape an id-swapping client takes: name a sponsee you do sponsor, and
 | a record you do not.
 */
it('refuses a record that is not the named sponsee\'s', function () use ($review) {
    $review($this, [
        'item_type' => 4, 'record_id' => $this->strangers->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertNotFound();

    expect((int) $this->strangers->fresh()->getRawOriginal('reviewed'))->toBe(0);
});

it('refuses while the sponsorship is only pending', function () use ($review) {
    Sponsor::query()->where('sponseeid', $this->sponsee->id)->update(['status' => Sponsor::PENDING]);

    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertForbidden();
});

/*
 | The relationship has a direction. A sponsee cannot mark their sponsor's
 | work reviewed by swapping the ids round.
 */
it('will not work in the sponsee to sponsor direction', function () use ($review) {
    $mine = Inventory::make()->forceFill([
        'accountid' => $this->sponsor->id, 'inventoryforstep' => 4,
        'invtitle' => 'My own', 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $mine->save();

    $review($this, [
        'item_type' => 4, 'record_id' => $mine->id,
        'friend_id' => $this->sponsor->id, 'reviewed' => 1,
    ])->assertForbidden();
});

it('will not mark a spot check from the Step Four screen', function () use ($review) {
    $spot = Inventory::make()->forceFill([
        'accountid' => $this->sponsee->id, 'inventoryforstep' => 10,
        'invtitle' => 'A spot check', 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $spot->save();

    $review($this, [
        'item_type' => 4, 'record_id' => $spot->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertNotFound();

    $review($this, [
        'item_type' => 10, 'record_id' => $spot->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk();

    expect((int) $spot->fresh()->getRawOriginal('reviewed'))->toBe(1);
});

it('refuses an item_type it does not recognise before touching the database', function () use ($review) {
    $review($this, [
        'item_type' => 99, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertStatus(400)->assertJsonPath('success', 0);
});

/*
 | The contract the client reads, which is not this application's to choose:
 | `mark_as_reviewed.php` is one of the three endpoints with no envelope, and
 | `sponsorship_repository.dart` reads `success` by hand. A `{status, message,
 | response}` body here is a 200 the client takes for a failure.
 */
it('answers {success: 1} and not the envelope', function () use ($review) {
    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk()
        ->assertJsonPath('success', 1)
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('record_id', $this->inventory->id)
        ->assertJsonPath('item_type', 4)
        ->assertJsonMissingPath('status')
        ->assertJsonMissingPath('response');
});

it('says so without writing when it is already in that state', function () use ($review) {
    $this->inventory->forceFill(['reviewed' => 1])->save();

    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk()
        ->assertJsonPath('success', 1)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('notified', 0);
});

it('marks nights and amends too, and can unmark', function () use ($review) {
    $night = Night::make()->forceFill([
        'accountid' => $this->sponsee->id, 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $night->save();

    $amend = Amend::make()->forceFill([
        'accountid' => $this->sponsee->id, 'amendstitle' => 'My brother',
        'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $amend->save();

    $review($this, ['item_type' => 11, 'record_id' => $night->id, 'friend_id' => $this->sponsee->id, 'reviewed' => 1])->assertOk();
    $review($this, ['item_type' => 89, 'record_id' => $amend->id, 'friend_id' => $this->sponsee->id, 'reviewed' => 1])->assertOk();

    expect((int) $night->fresh()->getRawOriginal('reviewed'))->toBe(1)
        ->and((int) $amend->fresh()->getRawOriginal('reviewed'))->toBe(1);

    $review($this, ['item_type' => 11, 'record_id' => $night->id, 'friend_id' => $this->sponsee->id, 'reviewed' => 0])->assertOk();

    expect((int) $night->fresh()->getRawOriginal('reviewed'))->toBe(0);
});

/*
 | `relationship_direction` records who asked, not who sponsors whom. A
 | sponsorship the sponsee asked for is the same sponsorship.
 */
it('does not care which way the request originally went', function () use ($review) {
    Sponsor::query()->where('sponseeid', $this->sponsee->id)
        ->update(['relationship_direction' => Sponsor::SPONSEE_TO_SPONSOR]);

    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk();

    expect((int) $this->inventory->fresh()->getRawOriginal('reviewed'))->toBe(1);
});

/*
 | The push is how the sponsee finds out, and both clients route it on
 | `data['table']` — an unknown or missing value is `IgnoreLink`. The live
 | script sends `record_id`, `item_type` and `reviewed`; `list_type` is added
 | so that a tap opens the record rather than refreshing a list, which is
 | what `PushRouter` does with it.
 */
it('tells the sponsee, with the keys their app routes on', function () use ($review) {
    $sender = new class implements PushSender
    {
        /** @var array<int, PushMessage> */
        public array $sent = [];

        public function send(array $tokens, PushMessage $message): int
        {
            $this->sent[] = $message;

            return count($tokens);
        }
    };
    $this->app->instance(PushSender::class, $sender);
    $this->sponsee->forceFill(['fcm_token' => 'sponsee-device'])->save();

    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk()->assertJsonPath('notified', 1);

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]->table())->toBe('ITEM_REVIEWED')
        ->and($sender->sent[0]->data['record_id'])->toBe((string) $this->inventory->id)
        ->and($sender->sent[0]->data['list_type'])->toBe('inventories');
});

it('does not announce an un-review', function () use ($review) {
    $sender = new class implements PushSender
    {
        /** @var array<int, PushMessage> */
        public array $sent = [];

        public function send(array $tokens, PushMessage $message): int
        {
            $this->sent[] = $message;

            return count($tokens);
        }
    };
    $this->app->instance(PushSender::class, $sender);
    $this->sponsee->forceFill(['fcm_token' => 'sponsee-device'])->save();
    $this->inventory->forceFill(['reviewed' => 1])->save();

    // Taking it back is a correction, not news.
    $review($this, [
        'item_type' => 4, 'record_id' => $this->inventory->id,
        'friend_id' => $this->sponsee->id, 'reviewed' => 0,
    ])->assertOk()->assertJsonPath('notified', 0);

    expect($sender->sent)->toBeEmpty();
});
