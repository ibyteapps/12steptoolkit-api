<?php

use App\Models\Account;
use App\Models\Amend;
use App\Models\InstallSecret;
use App\Models\Inventory;
use App\Models\Night;
use App\Models\Sponsor;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Support\Facades\DB;

use function Tests\Support\signAs;

/*
 | `get_sponsee_steps_data.php` answers about somebody else's records, which
 | is the whole reason it answers in counts. The live script takes both
 | `account_id` and `sponsor_id` from the body and checks neither, so any
 | signed-in person can read any member's overview — their sobriety date, how
 | many amends they still owe — by posting an id.
 */

beforeEach(function () {
    $make = function (array $extra = []): Account {
        $a = Account::make()->forceFill(array_merge(['nickname' => ' ', 'created' => now()], $extra));
        $a->save();

        return $a;
    };

    $this->sponsor = $make();
    $this->sponsee = $make(['sobrietydate' => '2019-04-02', 'sobrietytime' => '07:30', 'step2' => '2020-01-01']);
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
        'sponsorid' => $this->sponsor->id, 'sponseeid' => $this->sponsee->id,
        'status' => Sponsor::ACCEPTED, 'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);

    $this->ask = fn (array $fields = []) => signAs($this, '/api/v1/android/19/get_sponsee_steps_data.php', array_merge([
        'account_id' => $this->sponsee->id,
        'sponsor_id' => $this->sponsor->id,
    ], $fields));
});

it('answers the counts a sponsor needs', function () {
    Inventory::make()->forceFill([
        'accountid' => $this->sponsee->id, 'inventoryforstep' => 4, 'invtitle' => 'One',
        'shared' => 0, 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ])->save();
    Inventory::make()->forceFill([
        'accountid' => $this->sponsee->id, 'inventoryforstep' => 4, 'invtitle' => 'Two',
        'shared' => 1, 'reviewed' => 1, 'tstamp' => time(), 'created' => now(),
    ])->save();
    Inventory::make()->forceFill([
        'accountid' => $this->sponsee->id, 'inventoryforstep' => 10, 'invtitle' => 'Spot check',
        'apologyowed' => 1, 'apologydone' => 0, 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ])->save();
    Amend::make()->forceFill([
        'accountid' => $this->sponsee->id, 'amendstitle' => 'My brother',
        'amendsdone' => 0, 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ])->save();
    Night::make()->forceFill([
        'accountid' => $this->sponsee->id, 'reviewed' => 0, 'tstamp' => time(), 'created' => now(),
    ])->save();

    $response = ($this->ask)()->assertOk();

    $response->assertJsonPath('status', true)
        ->assertJsonPath('response.sobriety.date', '2019-04-02')
        ->assertJsonPath('response.step_dates.step2', '2020-01-01')
        ->assertJsonPath('response.step4.total', 2)
        ->assertJsonPath('response.step4.pending_share', 1)
        ->assertJsonPath('response.step4.pending_review', 1)
        ->assertJsonPath('response.step10.total', 1)
        ->assertJsonPath('response.step10.pending_apologies', 1)
        ->assertJsonPath('response.amends.total', 1)
        ->assertJsonPath('response.amends.pending_amends', 1)
        // The client reads `pending`; the live script sends `pending_amends`.
        // Both are answered, so neither side is wrong.
        ->assertJsonPath('response.amends.pending', 1)
        ->assertJsonPath('response.nights.total', 1)
        // Written tonight, so nothing is pending today. The live script
        // compares DATE(tstamp) against today's date on an epoch integer, so
        // it answers 1 here for everybody, every day.
        ->assertJsonPath('response.nights.pending_today', 0);
});

it('says the night is still to write when none was written today', function () {
    Night::make()->forceFill([
        'accountid' => $this->sponsee->id, 'reviewed' => 1,
        'tstamp' => now()->subDays(3)->getTimestamp(), 'created' => now(),
    ])->save();

    ($this->ask)()->assertOk()->assertJsonPath('response.nights.pending_today', 1);
});

it('counts the sponsor\'s own comments, by step, in both spellings', function () {
    foreach ([2, 4, 11, 89] as $step) {
        DB::table('comments')->insert([
            'accountid' => $this->sponsee->id, 'sponsorid' => $this->sponsor->id,
            'byid' => $this->sponsor->id, 'step' => $step, 'comment' => 'Well done',
            'tstamp' => time(),
        ]);
    }

    ($this->ask)()->assertOk()
        ->assertJsonPath('response.step_dates.comments.step2', 1)
        // The four the client reads and the live script never sends.
        ->assertJsonPath('response.step_dates.comments.step4', 1)
        ->assertJsonPath('response.step_dates.comments.step11', 1)
        ->assertJsonPath('response.step_dates.comments.step89', 1);
});

it('will not show a member they do not sponsor', function () {
    ($this->ask)(['account_id' => $this->stranger->id])->assertStatus(403);
});

it('will not show a member whose request is only pending', function () {
    Sponsor::query()->where('sponseeid', $this->sponsee->id)->update(['status' => Sponsor::PENDING]);

    ($this->ask)()->assertStatus(403);
});

it('lets somebody ask about themselves', function () {
    ($this->ask)(['account_id' => $this->sponsor->id])->assertOk();
});

it('ignores a sponsor_id that is not the caller', function () {
    // The live script counts comments from whatever `sponsor_id` it is given,
    // so posting somebody else's id reads their conversation counts.
    DB::table('comments')->insert([
        'accountid' => $this->sponsee->id, 'sponsorid' => $this->stranger->id,
        'byid' => $this->stranger->id, 'step' => 2, 'comment' => 'Not yours',
        'tstamp' => time(),
    ]);

    ($this->ask)(['sponsor_id' => $this->stranger->id])->assertOk()
        ->assertJsonPath('response.step_dates.comments.step2', 0);
});

it('refuses an unsigned request', function () {
    $this->post('/api/v1/android/19/get_sponsee_steps_data.php', [
        'account_id' => $this->sponsee->id, 'sponsor_id' => $this->sponsor->id,
    ])->assertStatus(401);
});
