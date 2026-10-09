<?php

use App\Models\Account;
use App\Models\Amend;
use App\Models\InstallSecret;
use App\Models\Inventory;
use App\Models\Night;
use App\Models\Sponsor;
use App\Services\Legacy\LegacyJwt;

use function Tests\Support\signAs;

/*
 | `18/reviewed.php` is
 |
 |     UPDATE $tablename SET reviewed=$reviewed WHERE id='$id'
 |
 | — no account, no sponsorship check, and the new value interpolated into the
 | statement. Any id marked any record reviewed for any member. These tests
 | are mostly about the cases that one would have allowed.
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

$review = fn (object $t, array $fields) => signAs($t, '/api/v1/android/19/mark_reviewed.php', $fields);

it('lets a sponsor mark a sponsee\'s inventory reviewed', function () use ($review) {
    $review($this, [
        'step' => 4, 'online_id' => $this->inventory->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk();

    expect((int) $this->inventory->fresh()->getRawOriginal('reviewed'))->toBe(1);
});

it('refuses a record belonging to somebody they do not sponsor', function () use ($review) {
    $review($this, [
        'step' => 4, 'online_id' => $this->strangers->id,
        'sponsee_id' => $this->stranger->id, 'reviewed' => 1,
    ])->assertForbidden();

    expect((int) $this->strangers->fresh()->getRawOriginal('reviewed'))->toBe(0);
});

/*
 | The shape an id-swapping client takes: name a sponsee you do sponsor, and
 | a record you do not.
 */
it('refuses a record that is not the named sponsee\'s', function () use ($review) {
    $review($this, [
        'step' => 4, 'online_id' => $this->strangers->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertNotFound();

    expect((int) $this->strangers->fresh()->getRawOriginal('reviewed'))->toBe(0);
});

it('refuses while the sponsorship is only pending', function () use ($review) {
    Sponsor::query()->where('sponseeid', $this->sponsee->id)->update(['status' => Sponsor::PENDING]);

    $review($this, [
        'step' => 4, 'online_id' => $this->inventory->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
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
        'step' => 4, 'online_id' => $mine->id,
        'sponsee_id' => $this->sponsor->id, 'reviewed' => 1,
    ])->assertForbidden();
});

it('will not mark a spot check from the Step Four screen', function () use ($review) {
    $spot = Inventory::make()->forceFill([
        'accountid' => $this->sponsee->id, 'inventoryforstep' => 10,
        'invtitle' => 'A spot check', 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ]);
    $spot->save();

    $review($this, [
        'step' => 4, 'online_id' => $spot->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertNotFound();

    $review($this, [
        'step' => 10, 'online_id' => $spot->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk();

    expect((int) $spot->fresh()->getRawOriginal('reviewed'))->toBe(1);
});

it('refuses a step it does not recognise before touching the database', function () use ($review) {
    $review($this, [
        'step' => 99, 'online_id' => $this->inventory->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertStatus(400);
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

    $review($this, ['step' => 11, 'online_id' => $night->id, 'sponsee_id' => $this->sponsee->id, 'reviewed' => 1])->assertOk();
    $review($this, ['step' => 89, 'online_id' => $amend->id, 'sponsee_id' => $this->sponsee->id, 'reviewed' => 1])->assertOk();

    expect((int) $night->fresh()->getRawOriginal('reviewed'))->toBe(1)
        ->and((int) $amend->fresh()->getRawOriginal('reviewed'))->toBe(1);

    $review($this, ['step' => 11, 'online_id' => $night->id, 'sponsee_id' => $this->sponsee->id, 'reviewed' => 0])->assertOk();

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
        'step' => 4, 'online_id' => $this->inventory->id,
        'sponsee_id' => $this->sponsee->id, 'reviewed' => 1,
    ])->assertOk();

    expect((int) $this->inventory->fresh()->getRawOriginal('reviewed'))->toBe(1);
});
