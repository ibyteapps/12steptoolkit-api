<?php

use App\Models\Account;
use App\Models\AccountSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The v8 surface, which is the one protocol in this application that cannot be
 * made safe — only survived. These tests are the terms of that survival.
 */
function apple(object $test, string $script, array $fields = [])
{
    return $test->post("/api/v1/apple/8/$script", $fields + [
        'serversecret' => config('legacy.apple.server_secret'),
    ]);
}

beforeEach(function (): void {
    config()->set('legacy.apple.enabled', true);
    config()->set('legacy.apple.server_secret', 'a-test-secret');

    // `$guarded = ['*']` on every adopted model — the application does not
    // mass-assign into a table it does not own. `forceFill` is how the rest of
    // the suite seeds one.
    $this->account = Account::make()->forceFill(['email' => 'a@example.test', 'created' => now()]);
    $this->account->save();

    $this->other = Account::make()->forceFill(['email' => 'b@example.test', 'created' => now()]);
    $this->other->save();
});

describe('the gate', function (): void {
    it('is actually in front of the routes', function (): void {
        // It was not, until 2026-10-07. Both v8 groups carried
        // `throttle:legacy-apple` and nothing else, so `VerifyAppleSecret` sat
        // in `bootstrap/app.php` under an alias no route used. A 503
        // controller hid it from every test anyone thought to write.
        $this->post('/api/v1/apple/8/reachable.php')
            ->assertOk()
            ->assertSee('', false);

        expect($this->post('/api/v1/apple/8/reachable.php')->getContent())
            ->toBe('', 'a missing secret must not reach the controller');
    });

    it('answers a wrong secret the way db.php does — an empty 200', function (): void {
        // `8/db.php` calls `exit(0)`. A 401 would show a different error in a
        // client built in 2021 than the one it already knows how to handle.
        $response = $this->post('/api/v1/apple/8/reachable.php', [
            'serversecret' => 'not-the-secret',
        ]);

        $response->assertOk();
        expect($response->getContent())->toBe('');
    });

    it('refuses everything when the secret is unset, rather than matching an empty field', function (): void {
        config()->set('legacy.apple.server_secret', '');

        expect($this->post('/api/v1/apple/8/reachable.php', ['serversecret' => ''])->getContent())
            ->toBe('');
    });

    it('is 410, not an empty 200, when v8 is switched off', function (): void {
        // A different answer on purpose: "this host is not serving v8" and
        // "your secret is wrong" are different facts and the operator pointing
        // a DNS record at this application should be able to tell them apart.
        config()->set('legacy.apple.enabled', false);

        $this->post('/api/v1/apple/8/reachable.php', [
            'serversecret' => 'a-test-secret',
        ])->assertStatus(410);
    });

    it('lets a correct secret through', function (): void {
        expect(apple($this, 'reachable.php')->getContent())->toBe('success');
    });
});

describe('getlist.php', function (): void {
    it('returns a JSON array, not hand-assembled bytes', function (): void {
        DB::table('journals')->insert([
            ['accountid' => $this->account->id, 'description' => 'one', 'tstamp' => 10],
            ['accountid' => $this->account->id, 'description' => 'two', 'tstamp' => 20],
        ]);

        $response = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 3,
        ]);

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $rows = $response->json();

        expect($rows)->toHaveCount(2);
        // `ORDER BY tstamp desc, id desc`.
        expect($rows[0]['description'])->toBe('two');
    });

    it('never returns another account\'s rows', function (): void {
        DB::table('journals')->insert([
            'accountid' => $this->other->id, 'description' => 'theirs', 'tstamp' => 1,
        ]);

        expect(apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 3,
        ])->json())->toBe([]);
    });

    it('answers an unknown type with an empty list, not with inventories', function (): void {
        // The old file defaults `$tablename` to `inventories` and its trailing
        // `else` queries it, so `type=7` returned somebody's Fourth Step.
        DB::table('inventories')->insert([
            'accountid' => $this->account->id, 'inventoryforstep' => 4, 'invtitle' => 'private', 'tstamp' => 1,
        ]);

        expect(apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 7,
        ])->json())->toBe([]);
    });

    it('projects nights narrowly, as the old SQL does', function (): void {
        // `getlist.php:56` selects `id, timestamp, thedate, reviewed` — not
        // `*`. A faithful-looking `SELECT *` here would put every user's whole
        // nightly inventory on the wire on every open of that screen.
        DB::table('nights')->insert([
            'accountid' => $this->account->id,
            'timestamp' => '2026-10-07',
            'thedate' => '2026-10-07',
            'desc1' => 'something personal',
            'tstamp' => 5,
        ]);

        $row = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 2,
        ])->json()[0];

        expect(array_keys($row))->toEqualCanonicalizing([
            'id', 'timestamp', 'thedate', 'reviewed', 'notifications',
        ]);
        expect($row)->not->toHaveKey('desc1');
    });

    it('step 5 lists the step 4 inventories still to share', function (): void {
        // The step number asked for is not the step number queried:
        // `inventoryforstep=4 and shared = 0`. Step 5 of the programme is
        // sharing the Fourth Step, so its list is what is left to share.
        DB::table('inventories')->insert([
            ['accountid' => $this->account->id, 'inventoryforstep' => 4, 'invtitle' => 'unshared', 'shared' => '0', 'tstamp' => 2],
            ['accountid' => $this->account->id, 'inventoryforstep' => 4, 'invtitle' => 'shared', 'shared' => '1', 'tstamp' => 1],
        ]);

        $rows = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 1,
            'step' => 5,
        ])->json();

        expect($rows)->toHaveCount(1);
        expect($rows[0]['invtitle'])->toBe('unshared');
    });

    it('step 9 lists the amends not yet made', function (): void {
        DB::table('amends')->insert([
            ['accountid' => $this->account->id, 'amendstitle' => 'owed', 'amendsdone' => 0, 'tstamp' => 2],
            ['accountid' => $this->account->id, 'amendstitle' => 'made', 'amendsdone' => 1, 'tstamp' => 1],
        ]);

        $rows = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 5,
            'step' => 9,
        ])->json();

        expect($rows)->toHaveCount(1);
        expect($rows[0]['amendstitle'])->toBe('owed');
    });

    it('counts unread comments per record, and not the viewer\'s own', function (): void {
        $id = DB::table('inventories')->insertGetId([
            'accountid' => $this->account->id, 'inventoryforstep' => 4, 'invtitle' => 'x', 'tstamp' => 1,
        ]);

        DB::table('comments')->insert([
            // From the sponsor, unseen — counts.
            ['accountid' => $this->account->id, 'sponsorid' => $this->other->id, 'byid' => $this->other->id, 'recordid' => $id, 'step' => 4, 'seen' => 0, 'comment' => 'a'],
            // The viewer's own — does not.
            ['accountid' => $this->account->id, 'sponsorid' => $this->other->id, 'byid' => $this->account->id, 'recordid' => $id, 'step' => 4, 'seen' => 0, 'comment' => 'b'],
            // Already seen — does not.
            ['accountid' => $this->account->id, 'sponsorid' => $this->other->id, 'byid' => $this->other->id, 'recordid' => $id, 'step' => 4, 'seen' => 1, 'comment' => 'c'],
        ]);

        $rows = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'sponsorid' => $this->other->id,
            'type' => 1,
            'step' => 4,
        ])->json();

        expect($rows[0]['notifications'])->toBe(1);
    });

    it('gives a record with no comments a zero rather than no key', function (): void {
        DB::table('inventories')->insert([
            'accountid' => $this->account->id, 'inventoryforstep' => 4, 'invtitle' => 'x', 'tstamp' => 1,
        ]);

        $rows = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'sponsorid' => $this->other->id,
            'type' => 1,
            'step' => 4,
        ])->json();

        // The old correlated subquery produced an integer for every row. A
        // missing key and a zero decode differently in Swift.
        expect($rows[0])->toHaveKey('notifications');
        expect($rows[0]['notifications'])->toBe(0);
    });

    it('leaves journals and gratitudes without the notifications column', function (): void {
        // 3, 4 and 15 are plain `SELECT *` in the original. Adding a column
        // the client's decoder has never seen is not a favour.
        DB::table('gratitudes')->insert([
            'accountid' => $this->account->id, 'description' => 'g', 'tstamp' => 1,
        ]);

        expect(apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 4,
        ])->json()[0])->not->toHaveKey('notifications');
    });

    it('does not let a directory filter carry SQL', function (): void {
        // `"and country = '" . $_POST["country"] . "'"`, interpolated, on the
        // one endpoint that takes free text from a filter form.
        DB::table('account_details')->insert([
            'accountid' => $this->account->id,
            'accept_new_sponsees' => 'true',
            'country' => 'Pakistan',
            'countrycode' => 'PK',
        ]);

        $response = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 50,
            'country' => "Pakistan' OR '1'='1",
        ]);

        $response->assertOk();
        expect($response->json())->toBe([]);

        // And the table is still there.
        expect(DB::table('account_details')->count())->toBe(1);
    });

    it('clamps an icon above the 41 bundled avatars to "0"', function (): void {
        // `getlist.php:163`, and the string rather than the integer is what
        // the client's decoder was built against.
        $this->account->forceFill(['icon' => 99])->save();
        DB::table('account_details')->insert([
            'accountid' => $this->account->id,
            'accept_new_sponsees' => 'true',
            'country' => 'UK',
            'countrycode' => 'GB',
        ]);

        $row = apple($this, 'getlist.php', [
            'accountid' => $this->account->id,
            'type' => 50,
        ])->json()[0];

        expect($row['icon'])->toBe('0');
    });
});

describe('deleterecord.php', function (): void {
    it('deletes the row and says success', function (): void {
        $id = DB::table('journals')->insertGetId([
            'accountid' => $this->account->id, 'description' => 'x', 'tstamp' => 1,
        ]);

        expect(apple($this, 'deleterecord.php', ['id' => $id, 'type' => 3])->getContent())
            ->toBe('success');
        expect(DB::table('journals')->count())->toBe(0);
    });

    it('deletes nothing when the type is unknown', function (): void {
        // The old script defaults to `inventories`, so a request with a
        // missing or unrecognised type deleted from somebody's Fourth Step.
        $id = DB::table('inventories')->insertGetId([
            'accountid' => $this->account->id, 'inventoryforstep' => 4, 'invtitle' => 'x', 'tstamp' => 1,
        ]);

        expect(apple($this, 'deleterecord.php', ['id' => $id])->getContent())->toBe('error');
        expect(apple($this, 'deleterecord.php', ['id' => $id, 'type' => 99])->getContent())->toBe('error');
        expect(DB::table('inventories')->count())->toBe(1);
    });

    it('does not take SQL in the id', function (): void {
        DB::table('journals')->insert([
            'accountid' => $this->account->id, 'description' => 'x', 'tstamp' => 1,
        ]);

        expect(apple($this, 'deleterecord.php', ['id' => "1' OR '1'='1", 'type' => 3])->getContent())
            ->toBe('error');
        expect(DB::table('journals')->count())->toBe(1);
    });

    it('refuses once the owner has signed in on 2.0', function (): void {
        // The only protection this endpoint can have. The client posts `id`,
        // `type` and a secret that is the same in every binary, so there is no
        // caller identity to scope a delete by — see AppleDelete's docblock.
        // Sealing is why that is survivable rather than permanent.
        $id = DB::table('journals')->insertGetId([
            'accountid' => $this->account->id, 'description' => 'x', 'tstamp' => 1,
        ]);

        AccountSecurity::query()->insert([
            'account_id' => $this->account->id,
            // `account_security` is one of this application's **own** tables,
            // so it has a uuid primary key and a NOT NULL on it.
            'uuid' => (string) Str::uuid(),
            'v1_sealed_at' => now(),
        ]);

        expect(apple($this, 'deleterecord.php', ['id' => $id, 'type' => 3])->getContent())
            ->toBe('error');
        expect(DB::table('journals')->count())->toBe(1);
    });
});

describe('the endpoints that cannot run', function (): void {
    it('keeps the four mysql_* scripts dead', function (): void {
        // `login.php`, `getcounts_apple.php`, `sobrietydate.php` and
        // `loguservisit.php` use `mysql_connect`, removed in PHP 7. They have
        // been fatalling in production for years, which is why iOS
        // email-and-password sign-in does not work. Reimplementing them would
        // not restore a feature; in `login.php`'s case it would introduce a
        // plaintext password comparison.
        foreach (['login.php', 'getcounts_apple.php', 'sobrietydate.php', 'loguservisit.php'] as $script) {
            $response = apple($this, $script);
            expect($response->getStatusCode())->toBe(500, $script);
            expect($response->getContent())->toBe('', $script);
        }
    });

    it('answers a script that is not built yet with 503, not with success', function (): void {
        $response = apple($this, 'writerecord.php');

        expect($response->getStatusCode())->toBe(503);
        // The client's test is `contains("success")`. A not-built response
        // that happened to contain the word would be read as a saved record.
        expect($response->getContent())->not->toContain('success');
    });
});
