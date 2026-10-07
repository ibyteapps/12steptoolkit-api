<?php

use App\Models\Account;
use App\Models\AccountDetail;
use App\Models\BlockedUser;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Models\InstallSecret;
use App\Models\Sponsor;

use function Tests\Support\signAs;

/**
 * Sponsorship and chat requests.
 *
 * `update_sponsors.php` is `UPDATE sponsors SET status = ?, rejected_by = ?
 * WHERE id = ?` with no account clause, so most of what is tested here is
 * refusals: who may accept, who may act at all, and what happens when the same
 * thing is done twice.
 */
beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => 'Jo', 'created' => now(), 'software_version' => 19]);
    $this->account->save();
    $this->other = Account::make()->forceFill(['nickname' => 'Sam', 'created' => now()]);
    $this->other->save();

    foreach ([$this->account, $this->other] as $a) {
        (new AccountDetail)->forceFill([
            'accountid' => $a->id, 'country' => 'United Kingdom', 'countrycode' => 'GB',
            'lastseen' => time(), 'age' => 3, 'gender' => 1, 'profession' => 2,
            'about' => 'Grateful', 'created' => now(),
        ])->save();
    }

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = $this->account->createToken('Test', ['*'], now()->addDay())->plainTextToken;
});

function relationship(int $sponsorId, int $sponseeId, array $attributes = []): Sponsor
{
    $s = new Sponsor;
    $s->forceFill(array_merge([
        'sponsorid' => $sponsorId,
        'sponseeid' => $sponseeId,
        'status' => Sponsor::PENDING,
        'relationship_direction' => Sponsor::SPONSEE_TO_SPONSOR,
        'requested_tstamp' => time(),
    ], $attributes))->save();

    return $s;
}

// ------------------------------------------------------------------- reading

it('returns relationships and the other person\'s profile', function () {
    relationship($this->other->id, $this->account->id, ['status' => Sponsor::ACCEPTED]);

    $r = signAs($this, '/api/v1/android/19/get_friends.php')->assertOk();

    expect($r->json('response.friends'))->toHaveCount(1)
        // Everything in `friends` is a string, as mysqli hands it back.
        ->and($r->json('response.friends.0.status'))->toBe('1')
        ->and($r->json('response.friends.0.is_updated_online'))->toBe('1')
        ->and($r->json('response.friends_details'))->toHaveCount(1)
        ->and($r->json('response.friends_details.0.nickname'))->toBe('Sam')
        // They are the sponsor and I am the sponsee, so friendType is 1.
        ->and($r->json('response.friends_details.0.friendType'))->toBe(1)
        ->and($r->json('response.friends_details.0.friendStatus'))->toBe(1);
});

it('derives friendType from which side of the row you are', function () {
    // I am the sponsor, so they are my sponsee: 2.
    relationship($this->account->id, $this->other->id, ['status' => Sponsor::ACCEPTED]);

    signAs($this, '/api/v1/android/19/get_friends.php')
        ->assertOk()
        ->assertJsonPath('response.friends_details.0.friendType', 2);
});

it('calls a chat relationship type 3, whichever side you are', function () {
    // Statuses 5 and 6 are the chat flow, which the schema comment does not
    // mention at all.
    relationship($this->account->id, $this->other->id, ['status' => Sponsor::CHAT_ACCEPTED]);

    signAs($this, '/api/v1/android/19/get_friends.php')
        ->assertOk()
        ->assertJsonPath('response.friends_details.0.friendType', 3)
        ->assertJsonPath('response.friends_details.0.friendStatus', 1);
});

it('keeps a rejected row but shows no profile for it', function () {
    // The client keeps rejected rows so it knows not to ask again; the profile
    // is not theirs to see any more.
    relationship($this->other->id, $this->account->id, ['status' => Sponsor::REJECTED]);

    $r = signAs($this, '/api/v1/android/19/get_friends.php')->assertOk();

    expect($r->json('response.friends'))->toHaveCount(1)
        ->and($r->json('response.friends_details'))->toBe([]);
});

it('shows nothing for somebody else\'s relationship', function () {
    $third = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $third->save();
    relationship($this->other->id, $third->id, ['status' => Sponsor::ACCEPTED]);

    signAs($this, '/api/v1/android/19/get_friends.php')
        ->assertOk()
        ->assertJsonPath('response.friends', [])
        ->assertJsonPath('response.friends_details', []);
});

it('gives one profile only to somebody with a live relationship', function () {
    signAs($this, '/api/v1/android/19/get_one_friend.php', ['friend_id' => $this->other->id])
        ->assertStatus(404);

    relationship($this->other->id, $this->account->id, ['status' => Sponsor::ACCEPTED]);

    signAs($this, '/api/v1/android/19/get_one_friend.php', ['friend_id' => $this->other->id])
        ->assertOk()
        ->assertJsonPath('response.nickname', 'Sam');
});

it('never puts an email or a password in a profile', function () {
    $this->other->forceFill(['email' => 'sam@example.com', 'password' => 'plaintext', 'hashed_password' => 'x'])->save();
    relationship($this->other->id, $this->account->id, ['status' => Sponsor::ACCEPTED]);

    $body = signAs($this, '/api/v1/android/19/get_friends.php')->assertOk()->getContent();

    expect($body)->not->toContain('sam@example.com')
        ->not->toContain('plaintext')
        ->not->toContain('hashed_password');
});

it('lists the row ids for reconciliation', function () {
    $a = relationship($this->other->id, $this->account->id);

    signAs($this, '/api/v1/android/19/fetch_sponsor_ids.php')
        ->assertOk()
        ->assertJsonPath('response', [(int) $a->id]);
});

it('says whether somebody has a sponsor', function () {
    signAs($this, '/api/v1/android/19/check_if_has_sponsor_or_old_device.php')
        ->assertOk()
        ->assertJsonPath('response.has_sponsor', false)
        ->assertJsonPath('response.software_version', 19);

    relationship($this->other->id, $this->account->id, ['status' => Sponsor::ACCEPTED]);

    signAs($this, '/api/v1/android/19/check_if_has_sponsor_or_old_device.php')
        ->assertOk()
        ->assertJsonPath('response.has_sponsor', true);
});

// ------------------------------------------------------------------ requests

it('sends a chat request and opens the thread it will use', function () {
    $r = signAs($this, '/api/v1/android/19/send_chat_request.php', [
        'sponsor_id' => $this->account->id, 'sponsee_id' => $this->other->id, 'thread_id' => 0,
    ])->assertOk();

    $threadId = $r->json('response.thread_id');

    expect($threadId)->toBeInt()->toBeGreaterThan(0)
        ->and((int) Sponsor::query()->sole()->status)->toBe(Sponsor::CHAT_PENDING)
        // Both parties subscribed, so the conversation is possible at once.
        ->and(CommentThreadSubscriber::query()->where('thread_id', $threadId)->count())->toBe(2)
        ->and((int) CommentThread::query()->sole()->is_group)->toBe(0);
});

it('does not make a second relationship or a second thread when asked twice', function () {
    foreach ([1, 2] as $i) {
        $ids[] = signAs($this, '/api/v1/android/19/send_chat_request.php', [
            'sponsor_id' => $this->account->id, 'sponsee_id' => $this->other->id,
        ])->assertOk()->json('response.thread_id');
    }

    expect(Sponsor::query()->count())->toBe(1)
        ->and(CommentThread::query()->count())->toBe(1)
        ->and($ids[0])->toBe($ids[1]);
});

it('refuses to open a relationship between two other people', function () {
    $third = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $third->save();

    signAs($this, '/api/v1/android/19/send_chat_request.php', [
        'sponsor_id' => $this->other->id, 'sponsee_id' => $third->id,
    ])->assertStatus(403);

    expect(Sponsor::query()->count())->toBe(0);
});

it('refuses a request to somebody who has blocked you', function () {
    BlockedUser::query()->insert([
        'blocker_id' => $this->other->id, 'blocked_id' => $this->account->id,
        'is_deleted' => 0, 'created_at' => now(),
    ]);

    signAs($this, '/api/v1/android/19/send_chat_request.php', [
        'sponsor_id' => $this->account->id, 'sponsee_id' => $this->other->id,
    ])->assertStatus(403);

    expect(Sponsor::query()->count())->toBe(0);
});

it('refuses a request to yourself', function () {
    signAs($this, '/api/v1/android/19/send_chat_request.php', [
        'sponsor_id' => $this->account->id, 'sponsee_id' => $this->account->id,
    ])->assertStatus(400);
});

// ------------------------------------------------------------------ updating

it('lets the other side accept, and opens the thread', function () {
    // They asked (direction 2 = sponsee → sponsor), so I accept.
    $rel = relationship($this->other->id, $this->account->id, [
        'relationship_direction' => Sponsor::SPONSEE_TO_SPONSOR,
        'sponseeid' => $this->other->id,
        'sponsorid' => $this->account->id,
    ]);

    $r = signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $rel->id, 'status' => Sponsor::ACCEPTED,
    ])->assertOk();

    expect((int) $rel->fresh()->status)->toBe(Sponsor::ACCEPTED)
        ->and((int) $rel->fresh()->accepted_tstamp)->toBeGreaterThan(0)
        ->and($r->json('response.thread_id'))->toBeGreaterThan(0)
        ->and(CommentThreadSubscriber::query()->count())->toBe(2);
});

it('will not let the person who asked accept their own request', function () {
    // The whole of a request is that the other person answers it.
    $rel = relationship($this->account->id, $this->other->id, [
        'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
    ]);

    signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $rel->id, 'status' => Sponsor::ACCEPTED,
    ])->assertStatus(403);

    expect((int) $rel->fresh()->status)->toBe(Sponsor::PENDING)
        ->and(CommentThread::query()->count())->toBe(0);
});

it('will not let a stranger touch a relationship', function () {
    // `UPDATE sponsors SET status = ?, rejected_by = ? WHERE id = ?` — the old
    // script has no account clause, so this worked with a guessed id.
    $third = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $third->save();
    $theirs = relationship($this->other->id, $third->id);

    signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $theirs->id, 'status' => Sponsor::ACCEPTED,
    ])->assertStatus(403);

    expect((int) $theirs->fresh()->status)->toBe(Sponsor::PENDING);
});

it('records who rejected, and who blocked', function () {
    $rel = relationship($this->other->id, $this->account->id);

    signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $rel->id, 'status' => Sponsor::REJECTED,
    ])->assertOk();

    expect((int) $rel->fresh()->rejected_by)->toBe((int) $this->account->id)
        ->and((int) $rel->fresh()->rejected_tstamp)->toBeGreaterThan(0);

    $other = relationship($this->other->id, $this->account->id);
    signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $other->id, 'status' => Sponsor::BLOCKED,
    ])->assertOk();

    expect((int) $other->fresh()->blocked_by)->toBe((int) $this->account->id);
});

it('accepts a chat request into the same thread, not a second one', function () {
    signAs($this, '/api/v1/android/19/send_chat_request.php', [
        'sponsor_id' => $this->other->id, 'sponsee_id' => $this->account->id,
    ]);
    $rel = Sponsor::query()->sole();
    $rel->forceFill(['relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE])->save();

    signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $rel->id, 'status' => Sponsor::CHAT_ACCEPTED,
    ])->assertOk();

    expect(CommentThread::query()->count())->toBe(1)
        ->and(CommentThreadSubscriber::query()->count())->toBe(2);
});

it('refuses a status it does not recognise', function () {
    $rel = relationship($this->other->id, $this->account->id);

    signAs($this, '/api/v1/android/19/update_sponsors.php', [
        'local_id' => $rel->id, 'status' => 99,
    ])->assertStatus(400);

    expect((int) $rel->fresh()->status)->toBe(Sponsor::PENDING);
});
