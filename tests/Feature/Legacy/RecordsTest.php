<?php

use App\Models\Account;
use App\Models\InstallSecret;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Night;
use App\Services\Legacy\LegacyJwt;

use function Tests\Support\signAs;

beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $this->account->save();

    $this->other = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $this->other->save();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id,
        'device_id' => $this->deviceId,
        'secret' => $this->secret,
        'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];
});

it('writes, reads back and deletes an inventory', function () {
    $write = signAs($this, '/api/v1/android/19/inventory_add_update.php', [
        'id' => '7',
        'online_id' => 0,
        'inventoryforstep' => 4,
        'invtype' => 1,
        'invtitle' => 'My old boss',
        'invdescription' => 'What happened.',
        'tstamp' => 1760000000,
    ])->assertOk();

    // Every value in a write response is `strval()`'d by the old scripts, and
    // the clients parse strings.
    $write->assertJsonPath('response.id', '7')
        ->assertJsonPath('response.action', 'ADD')
        ->assertJsonPath('response.is_deleted', '0');

    $onlineId = (int) $write->json('response.online_id');
    expect($onlineId)->toBeGreaterThan(0);

    $read = signAs($this, '/api/v1/android/19/get_inventories.php')->assertOk();
    $read->assertJsonPath('response.0.onlineID', $onlineId)
        ->assertJsonPath('response.0.invtitle', 'My old boss')
        // `inventoryforstep` is cast to int by the reader; `invtitle` is not.
        ->assertJsonPath('response.0.inventoryforstep', 4);

    signAs($this, '/api/v1/android/19/inventory_add_update.php', [
        'id' => '7', 'online_id' => $onlineId, 'is_deleted' => 1,
    ])->assertOk()->assertJsonPath('response.action', 'DELETE');

    expect(Inventory::query()->count())->toBe(0);
});

it('refuses to write into another account', function () {
    // The old writers take `account_id` from the body and rely on the WHERE
    // clause to contain the damage. This refuses outright.
    signAs($this, '/api/v1/android/19/inventory_add_update.php', [
        'account_id' => $this->other->id,
        'online_id' => 0,
        'invtitle' => 'Not mine',
    ])->assertStatus(403);

    expect(Inventory::query()->count())->toBe(0);
});

it('cannot read another account\'s records', function () {
    (new Inventory)->forceFill([
        'accountid' => $this->other->id,
        'invtitle' => 'Theirs',
        'tstamp' => 1,
        'created' => now(),
    ])->save();

    signAs($this, '/api/v1/android/19/get_inventories.php')
        ->assertOk()
        ->assertJsonPath('response', []);
});

it('cannot delete another account\'s record by id', function () {
    $theirs = (new Inventory)->forceFill([
        'accountid' => $this->other->id, 'invtitle' => 'Theirs', 'tstamp' => 1, 'created' => now(),
    ]);
    $theirs->save();

    signAs($this, '/api/v1/android/19/inventory_add_update.php', [
        'online_id' => $theirs->id, 'is_deleted' => 1,
    ])->assertOk();

    expect(Inventory::query()->count())->toBe(1);
});

it('refuses an empty journal, as the server would', function () {
    signAs($this, '/api/v1/android/19/journal_add_update.php', ['online_id' => 0, 'description' => '  '])
        ->assertStatus(400);

    expect(Journal::query()->count())->toBe(0);
});

it('writes the eleven night switches and twelve answers, and no sw8', function () {
    $fields = ['online_id' => 0, 'tstamp' => 1760000000];
    foreach (Night::SWITCHES as $i => $sw) {
        $fields[$sw] = $i % 2 === 0 ? 'Yes' : 'No';
    }
    foreach (Night::DESCRIPTIONS as $i => $desc) {
        $fields[$desc] = 'answer '.($i + 1);
    }
    // A client that sends sw8 anyway: the column may not exist in production,
    // so it is never written.
    $fields['sw8'] = 'Yes';

    signAs($this, '/api/v1/android/19/night_add_update.php', $fields)->assertOk();

    $row = signAs($this, '/api/v1/android/19/get_nights.php')->assertOk()->json('response.0');

    expect($row)->not->toHaveKey('sw8');
    expect($row['sw1'])->toBe('Yes');
    expect($row['sw2'])->toBe('No');
    expect($row['desc8'])->toBe('answer 8');
});

it('normalises a switch to Yes or No, defaulting to No', function () {
    signAs($this, '/api/v1/android/19/night_add_update.php', [
        'online_id' => 0, 'sw1' => 'yes', 'sw2' => '1', 'sw3' => 'maybe', 'tstamp' => 1,
    ])->assertOk();

    $row = signAs($this, '/api/v1/android/19/get_nights.php')->json('response.0');

    expect($row['sw1'])->toBe('Yes');
    expect($row['sw2'])->toBe('Yes');
    expect($row['sw3'])->toBe('No');
});

it('counts the six collections', function () {
    (new Inventory)->forceFill(['accountid' => $this->account->id, 'tstamp' => 1, 'created' => now()])->save();
    (new Journal)->forceFill(['accountid' => $this->account->id, 'description' => 'x', 'tstamp' => 1, 'created' => now()])->save();
    (new Inventory)->forceFill(['accountid' => $this->other->id, 'tstamp' => 1, 'created' => now()])->save();

    signAs($this, '/api/v1/android/19/get_counts.php')
        ->assertOk()
        ->assertJsonPath('response.inventories', 1)
        ->assertJsonPath('response.journals', 1)
        ->assertJsonPath('response.nights', 0);
});

it('refuses an update to a record that is no longer there', function () {
    // The old code reports success for an UPDATE that matched nothing, so a
    // client retries for ever against a row somebody deleted on another device.
    signAs($this, '/api/v1/android/19/inventory_add_update.php', [
        'online_id' => 999999, 'invtitle' => 'Gone',
    ])->assertStatus(404);
});

it('answers app settings under `data`, not `response`', function () {
    // The one endpoint in the v19 API that breaks its own envelope. Both
    // clients parse it that way.
    $this->get('/api/v1/android/19/get_app_settings.php')
        ->assertOk()
        ->assertJsonPath('data.MAX_TITLE', 30)
        ->assertJsonMissingPath('response');
});
