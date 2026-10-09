<?php

use App\Models\Account;
use App\Models\InstallSecret;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Sponsor;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Support\Facades\DB;

use function Tests\Support\signAs;

/*
 | `18/deleteaccount.php` reads the id to erase out of the POST body and has
 | no authentication, so a request naming any id erased that member. It also
 | runs eleven statements outside a transaction, clears `password` but not
 | `hashed_password`, and forgets `accept_new_sponsees` — which Apple's copy
 | of the same script remembers.
 */

beforeEach(function () {
    $make = function (string $email): Account {
        $a = Account::make()->forceFill([
            'nickname' => 'Jo', 'email' => $email, 'created' => now(),
            'password' => 'plaintext', 'hashed_password' => bcrypt('x'),
            'sobrietydate' => '2012-03-07', 'fcm_token' => 'tok',
        ]);
        $a->save();

        return $a;
    };

    $this->account = $make('jo@example.com');
    $this->other = $make('sam@example.com');

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];

    foreach ([$this->account, $this->other] as $a) {
        Journal::make()->forceFill([
            'accountid' => $a->id, 'description' => 'An entry', 'tstamp' => time(), 'created' => now(),
        ])->save();
        Inventory::make()->forceFill([
            'accountid' => $a->id, 'inventoryforstep' => 4, 'invtitle' => 'A resentment',
            'tstamp' => time(), 'created' => now(),
        ])->save();
    }

    DB::table('account_details')->insert([
        'accountid' => $this->account->id, 'about' => 'About me',
        'phonenumber' => '07000 000000', 'accept_new_sponsees' => 'true',
    ]);

    Sponsor::query()->insert([
        'sponsorid' => $this->other->id, 'sponseeid' => $this->account->id,
        'status' => Sponsor::ACCEPTED, 'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);
});

$erase = fn (object $t, array $fields = ['confirm' => 'DELETE']) => signAs($t, '/api/v1/android/19/delete_account.php', $fields);

it('erases the caller and nobody else', function () use ($erase) {
    $erase($this)->assertOk();

    expect(Journal::query()->where('accountid', $this->account->id)->count())->toBe(0)
        ->and(Inventory::query()->where('accountid', $this->account->id)->count())->toBe(0)
        // The other member is untouched.
        ->and(Journal::query()->where('accountid', $this->other->id)->count())->toBe(1)
        ->and(Inventory::query()->where('accountid', $this->other->id)->count())->toBe(1);
});

/*
 | The whole point. There is no parameter that names whose account goes, so
 | naming somebody else's can only be read as a mismatch with the signature.
 */
it('has no way to name another account', function () use ($erase) {
    $erase($this, ['confirm' => 'DELETE', 'accountid' => $this->other->id, 'account_id' => $this->other->id])
        ->assertForbidden();

    expect(Journal::query()->where('accountid', $this->other->id)->count())->toBe(1)
        ->and(Account::find($this->other->id)->email)->toBe('sam@example.com');
});

it('keeps the account row and empties it', function () use ($erase) {
    $erase($this)->assertOk();

    $row = DB::table('accounts')->where('id', $this->account->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->email)->toBe('DELETED')
        ->and($row->sobrietydate)->toBe('')
        ->and($row->fcm_token)->toBe('')
        // A single space, which is how login.php creates a nickname — so one
        // erased account reads the same as another.
        ->and($row->nickname)->toBe(' ');
});

/*
 | The old script clears `password` and leaves `hashed_password`, which is
 | what Android authenticates against. An erased account could still be
 | signed into.
 */
it('clears both password columns, not just the one the old script knew', function () use ($erase) {
    $erase($this)->assertOk();

    $row = DB::table('accounts')->where('id', $this->account->id)->first();

    expect($row->password)->toBe('')
        ->and($row->hashed_password)->toBe('');
});

/*
 | Apple's copy of the script sets this and Android's forgets, which leaves an
 | erased account still advertising itself in the sponsor directory.
 */
it('stops the erased account offering to sponsor people', function () use ($erase) {
    $erase($this)->assertOk();

    $details = DB::table('account_details')->where('accountid', $this->account->id)->first();

    expect($details->accept_new_sponsees)->toBe('false')
        ->and($details->about)->toBe('')
        ->and($details->phonenumber)->toBe('');
});

it('revokes the credentials the device is holding', function () use ($erase) {
    $erase($this)->assertOk();

    expect(DB::table('install_secrets')->where('account_id', $this->account->id)->count())->toBe(0);
});

it('removes the sponsorship from both sides', function () use ($erase) {
    $erase($this)->assertOk();

    expect(Sponsor::query()->where('sponseeid', $this->account->id)->count())->toBe(0)
        ->and(Sponsor::query()->where('sponsorid', $this->other->id)->count())->toBe(0);
});

it('will not erase without the confirmation word', function () use ($erase) {
    $erase($this, [])->assertStatus(400);
    $erase($this, ['confirm' => 'yes'])->assertStatus(400);

    expect(Journal::query()->where('accountid', $this->account->id)->count())->toBe(1);
});

it('refuses an unsigned request', function () {
    $this->postJson('/api/v1/android/19/delete_account.php', ['confirm' => 'DELETE'])
        ->assertUnauthorized();

    expect(Journal::query()->where('accountid', $this->account->id)->count())->toBe(1);
});
