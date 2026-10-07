<?php

use App\Models\Account;
use App\Models\BlockedUser;
use App\Models\Comment;
use App\Models\CommentReceipt;
use App\Models\CommentStar;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Models\InstallSecret;
use App\Models\ReportedUser;

use function Tests\Support\signAs;

/**
 * Chat and step comments.
 *
 * The tests that matter most here are the visibility ones. `get_comments.php`
 * does not return a thread's messages to its members — it returns the messages
 * posted *while that member was subscribed*, which is a real promise in a
 * recovery app and the easiest thing in this file to lose by simplifying.
 */
beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => 'Jo', 'created' => now()]);
    $this->account->save();

    $this->other = Account::make()->forceFill(['nickname' => 'Sam', 'email' => 'sam@example.com', 'created' => now()]);
    $this->other->save();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id,
        'device_id' => $this->deviceId,
        'secret' => $this->secret,
        'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = $this->account->createToken('Test', ['*'], now()->addDay())->plainTextToken;
});

function thread(array $attributes = []): CommentThread
{
    $t = new CommentThread;
    $t->forceFill(array_merge([
        'title' => 'Tuesday group',
        'description' => ' ',
        'created_by' => 1,
        'created' => time(),
        'is_group' => 1,
        'is_public' => 0,
    ], $attributes))->save();

    return $t;
}

function subscribe(int $threadId, int $accountId, array $attributes = []): CommentThreadSubscriber
{
    $s = new CommentThreadSubscriber;
    $s->forceFill(array_merge([
        'thread_id' => $threadId,
        'account_id' => $accountId,
        'subscribed_at' => time() - 3600,
        'is_subscribed' => 1,
        'is_deleted' => 0,
        'is_admin' => 0,
    ], $attributes))->save();

    return $s;
}

function message(int $threadId, int $accountId, string $body, ?int $at = null): Comment
{
    $c = new Comment;
    $c->forceFill([
        'thread_id' => $threadId,
        'accountid' => $accountId,
        'byid' => $accountId,
        'comment' => $body,
        'tstamp' => $at ?? time(),
        'time_sent' => now()->toDateTimeString(),
    ])->save();

    return $c;
}

// --------------------------------------------------------------- visibility

it('returns a thread\'s messages to a subscribed member', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    message($t->id, $this->other->id, 'Morning all');
    message($t->id, $this->account->id, 'Morning');

    $r = signAs($this, '/api/v1/android/19/get_comments.php', ['thread_id' => $t->id])->assertOk();

    expect($r->json('response.counts.comments'))->toBe(2)
        ->and(collect($r->json('response.comments'))->pluck('comment')->all())
        ->toBe(['Morning all', 'Morning']);
});

it('does not show what was said before somebody joined', function () {
    // The promise: people say things in a group on the understanding that the
    // room is who is in it.
    $t = thread();
    $joined = time() - 600;
    subscribe($t->id, $this->account->id, ['subscribed_at' => $joined]);

    message($t->id, $this->other->id, 'Before they arrived', $joined - 300);
    message($t->id, $this->other->id, 'After they arrived', $joined + 300);

    $r = signAs($this, '/api/v1/android/19/get_comments.php', ['thread_id' => $t->id])->assertOk();

    expect(collect($r->json('response.comments'))->pluck('comment')->all())
        ->toBe(['After they arrived']);
});

it('shows nothing to somebody who has left', function () {
    $t = thread();
    subscribe($t->id, $this->account->id, ['is_subscribed' => 0, 'is_deleted' => 1]);
    message($t->id, $this->other->id, 'Said after they left');

    $r = signAs($this, '/api/v1/android/19/get_comments.php', ['thread_id' => $t->id])->assertOk();

    expect($r->json('response.comments'))->toBe([])
        ->and($r->json('response.counts.comments'))->toBe(0);
});

it('shows nothing from a thread somebody was never in', function () {
    $t = thread();
    subscribe($t->id, $this->other->id);
    message($t->id, $this->other->id, 'Private to them');

    signAs($this, '/api/v1/android/19/get_comments.php', ['thread_id' => $t->id])
        ->assertOk()
        ->assertJsonPath('response.comments', []);
});

it('gathers every thread when thread_id is 0, as the first sync does', function () {
    $a = thread(['title' => 'One']);
    $b = thread(['title' => 'Two']);
    subscribe($a->id, $this->account->id);
    subscribe($b->id, $this->account->id);
    message($a->id, $this->account->id, 'In one', time() - 10);
    message($b->id, $this->account->id, 'In two', time());

    $r = signAs($this, '/api/v1/android/19/get_comments.php', ['thread_id' => 0])->assertOk();

    // Sorted by tstamp across threads, as the old reader does.
    expect(collect($r->json('response.comments'))->pluck('comment')->all())->toBe(['In one', 'In two']);
});

// ------------------------------------------------------------------ threads

it('returns threads, their members and who is blocked', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    subscribe($t->id, $this->other->id);
    BlockedUser::query()->insert([
        'blocker_id' => $this->account->id, 'blocked_id' => 99, 'is_deleted' => 0, 'created_at' => now(),
    ]);

    $r = signAs($this, '/api/v1/android/19/get_comment_threads.php')->assertOk();

    expect($r->json('response.commentThreads'))->toHaveCount(1)
        ->and($r->json('response.commentThreads.0.comment_thread_id'))->toBe($t->id)
        // The old reader sends `id` as well, and the Room entity maps on both.
        ->and($r->json('response.commentThreads.0.id'))->toBe($t->id)
        ->and($r->json('response.commentThreadSubscribers'))->toHaveCount(2)
        ->and($r->json('response.commentThreadSubscribers.0.is_updated_online'))->toBe(1)
        ->and($r->json('response.blockedUsers'))->toHaveCount(1);
});

it('does not list a thread somebody is not in', function () {
    $t = thread();
    subscribe($t->id, $this->other->id);

    signAs($this, '/api/v1/android/19/get_comment_threads.php')
        ->assertOk()
        ->assertJsonPath('response.commentThreads', []);
});

// ------------------------------------------------------------------- writing

it('posts a message and answers in the old shape', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);

    $r = signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'thread_id' => $t->id,
        'comment' => 'Thinking of you all',
        'comment_id' => 0,
        'tstamp' => time(),
        'time_sent' => now()->toDateTimeString(),
    ])->assertOk();

    // `comment_id` at the top level as well as inside `response`, and every
    // value in `response` a string.
    expect($r->json('comment_id'))->toBeInt()->toBeGreaterThan(0)
        ->and($r->json('response.action'))->toBe('ADD')
        ->and($r->json('response.table'))->toBe('COMMENTS')
        ->and($r->json('response.status'))->toBe('1')
        ->and($r->json('response.deleted'))->toBe('0')
        ->and($r->json('response.time_read'))->toBe('0000-00-00 00:00:00')
        ->and(Comment::query()->count())->toBe(1);
});

it('refuses to post into a thread somebody is not in', function () {
    $t = thread();
    subscribe($t->id, $this->other->id);

    signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'thread_id' => $t->id, 'comment' => 'Barging in', 'comment_id' => 0,
    ])->assertStatus(403);

    expect(Comment::query()->count())->toBe(0);
});

it('refuses to post after leaving', function () {
    $t = thread();
    subscribe($t->id, $this->account->id, ['is_deleted' => 1, 'is_subscribed' => 0]);

    signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'thread_id' => $t->id, 'comment' => 'Still here?', 'comment_id' => 0,
    ])->assertStatus(403);
});

it('edits only its own message, and only its text', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    $mine = message($t->id, $this->account->id, 'Typo heer');

    signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'comment_id' => $mine->id,
        'comment' => 'Typo here',
        // The old UPDATE sets `comment` and `deleted` only, so a client cannot
        // rewrite a message's thread, author or time by resending the lot.
        'thread_id' => 999,
        'account_id' => $this->account->id,
        'tstamp' => 1,
    ])->assertOk()->assertJsonPath('response.action', 'UPDATE');

    $fresh = $mine->fresh();
    expect($fresh->comment)->toBe('Typo here')
        ->and((int) $fresh->thread_id)->toBe($t->id)
        ->and((int) $fresh->tstamp)->not->toBe(1);
});

it('refuses to edit somebody else\'s message', function () {
    // `UPDATE comments SET comment = ?, deleted = ? WHERE id = ?` — the old
    // script has no account clause at all, so this worked.
    $t = thread();
    subscribe($t->id, $this->account->id);
    $theirs = message($t->id, $this->other->id, 'Something personal');

    signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'comment_id' => $theirs->id, 'comment' => 'Rewritten', 'is_deleted' => 1,
    ])->assertStatus(403);

    expect($theirs->fresh()->comment)->toBe('Something personal')
        ->and((int) $theirs->fresh()->deleted)->toBe(0);
});

it('soft-deletes, as the client expects', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    $mine = message($t->id, $this->account->id, 'Never mind');

    signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'comment_id' => $mine->id, 'comment' => 'Never mind', 'is_deleted' => 1,
    ])->assertOk();

    expect((int) $mine->fresh()->deleted)->toBe(1)
        ->and(Comment::query()->count())->toBe(1);
});

it('refuses an empty message', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);

    signAs($this, '/api/v1/android/19/comment_add_update.php', [
        'thread_id' => $t->id, 'comment' => '   ', 'comment_id' => 0,
    ])->assertStatus(400);
});

// ---------------------------------------------------------- stars, receipts

it('stars and unstars, idempotently', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    $c = message($t->id, $this->other->id, 'Worth keeping');

    foreach ([0, 0, 1] as $deleted) {
        signAs($this, '/api/v1/android/19/comment_star.php', [
            'comment_id' => $c->id, 'is_deleted' => $deleted,
        ])->assertOk();
    }

    // UNIQUE (account_id, comment_id): one row, last state wins.
    expect(CommentStar::query()->count())->toBe(1)
        ->and((int) CommentStar::query()->sole()->is_deleted)->toBe(1);
});

it('will not star a message it cannot see', function () {
    $t = thread();
    subscribe($t->id, $this->other->id);
    $c = message($t->id, $this->other->id, 'Not for them');

    signAs($this, '/api/v1/android/19/comment_star.php', ['comment_id' => $c->id])->assertStatus(403);

    expect(CommentStar::query()->count())->toBe(0);
});

it('never moves a receipt backwards', function () {
    // The old script writes whatever it is sent, so a retry carrying a stale
    // value un-reads a message that had been read.
    $t = thread();
    subscribe($t->id, $this->account->id);
    $c = message($t->id, $this->other->id, 'Read this');

    $now = time();
    signAs($this, '/api/v1/android/19/comment_update_receipt.php', [
        'comment_id' => $c->id, 'time_delivered' => $now, 'time_read' => $now,
    ])->assertOk();

    signAs($this, '/api/v1/android/19/comment_update_receipt.php', [
        'comment_id' => $c->id, 'time_delivered' => 0, 'time_read' => 0,
    ])->assertOk();

    $receipt = CommentReceipt::query()->sole();
    expect((int) $receipt->time_read)->toBe($now)
        ->and((int) $receipt->time_delivered)->toBe($now)
        ->and(CommentReceipt::query()->count())->toBe(1);
});

// ---------------------------------------------------- membership, moderation

it('lets somebody leave, which closes their window', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    message($t->id, $this->other->id, 'While they were here');

    signAs($this, '/api/v1/android/19/update_thread_subscriber.php', [
        'thread_id' => $t->id, 'is_deleted' => 1,
    ])->assertOk();

    $row = CommentThreadSubscriber::query()->where('account_id', $this->account->id)->sole();
    expect((int) $row->is_deleted)->toBe(1)
        ->and((int) $row->is_subscribed)->toBe(0);

    signAs($this, '/api/v1/android/19/get_comments.php', ['thread_id' => $t->id])
        ->assertOk()
        ->assertJsonPath('response.comments', []);
});

it('will not let a member change somebody else\'s membership', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    subscribe($t->id, $this->other->id);

    signAs($this, '/api/v1/android/19/update_thread_subscriber.php', [
        'thread_id' => $t->id, 'account_id' => $this->other->id, 'is_deleted' => 1,
    ])->assertStatus(403);

    expect((int) CommentThreadSubscriber::query()->where('account_id', $this->other->id)->sole()->is_deleted)->toBe(0);
});

it('lets an admin remove somebody, but nobody promote themselves', function () {
    $t = thread();
    subscribe($t->id, $this->account->id, ['is_admin' => 1]);
    subscribe($t->id, $this->other->id);

    signAs($this, '/api/v1/android/19/update_thread_subscriber.php', [
        'thread_id' => $t->id, 'account_id' => $this->other->id, 'is_deleted' => 1,
    ])->assertOk();

    expect((int) CommentThreadSubscriber::query()->where('account_id', $this->other->id)->sole()->is_deleted)->toBe(1);

    // And a plain member cannot make themselves one.
    $third = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $third->save();
    $t2 = thread(['title' => 'Another']);
    subscribe($t2->id, $this->account->id);

    signAs($this, '/api/v1/android/19/update_thread_subscriber.php', [
        'thread_id' => $t2->id, 'is_admin' => 1,
    ])->assertOk();

    expect((int) CommentThreadSubscriber::query()->where('thread_id', $t2->id)->sole()->is_admin)->toBe(0);
});

it('blocks, idempotently, and hides the conversation both ways', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);
    subscribe($t->id, $this->other->id);
    message($t->id, $this->other->id, 'Before the block');

    foreach ([1, 2] as $i) {
        signAs($this, '/api/v1/android/19/block_report_user.php', [
            'target_id' => $this->other->id, 'action' => 'block', 'reason_code' => 2,
        ])->assertOk();
    }

    // UNIQUE (blocker_id, blocked_id): the old script's plain INSERT is a
    // duplicate-key error the second time.
    expect(BlockedUser::query()->count())->toBe(1);

    signAs($this, '/api/v1/android/19/get_comment_for_account_id.php', ['friend_id' => $this->other->id])
        ->assertOk()
        ->assertJsonPath('response.comments', []);
});

it('unblocks only its own blocks', function () {
    BlockedUser::query()->insert([
        'blocker_id' => $this->other->id, 'blocked_id' => $this->account->id, 'is_deleted' => 0, 'created_at' => now(),
    ]);

    signAs($this, '/api/v1/android/19/unblock_user.php', [
        'blocker_id' => $this->other->id, 'blocked_id' => $this->account->id,
    ])->assertStatus(403);

    expect(BlockedUser::query()->count())->toBe(1);
});

it('reports somebody, for the console to deal with', function () {
    $t = thread();
    subscribe($t->id, $this->account->id);

    signAs($this, '/api/v1/android/19/block_report_user.php', [
        'target_id' => $this->other->id, 'thread_id' => $t->id,
        'action' => 'report', 'reason_code' => 3, 'reason' => 'Selling something',
    ])->assertOk();

    $report = ReportedUser::query()->sole();
    expect((int) $report->reporter_id)->toBe((int) $this->account->id)
        ->and((int) $report->reported_id)->toBe((int) $this->other->id)
        ->and($report->status)->toBe('pending');
});

it('refuses to act as somebody else', function () {
    // `block_report_user.php` takes `user_id` from the body and acts as them.
    signAs($this, '/api/v1/android/19/block_report_user.php', [
        'user_id' => $this->other->id, 'target_id' => 5, 'action' => 'block',
    ])->assertStatus(403);

    expect(BlockedUser::query()->count())->toBe(0);
});

it('lists blocked people with their nickname, skipping deleted accounts', function () {
    $gone = Account::make()->forceFill(['nickname' => 'Gone', 'email' => 'DELETED', 'created' => now()]);
    $gone->save();

    foreach ([$this->other->id, $gone->id] as $id) {
        BlockedUser::query()->insert([
            'blocker_id' => $this->account->id, 'blocked_id' => $id, 'is_deleted' => 0, 'created_at' => now(),
        ]);
    }

    $r = signAs($this, '/api/v1/android/19/get_blocked_users.php')->assertOk();

    expect($r->json('response'))->toHaveCount(1)
        ->and($r->json('response.0.nickname'))->toBe('Sam');
});

it('returns step comments with empty reactions, as the old script does', function () {
    message(0, $this->account->id, 'On my fourth step')->forceFill(['step' => 4])->save();

    $r = signAs($this, '/api/v1/android/19/get_step_comments.php')->assertOk();

    expect($r->json('response.comments'))->toHaveCount(1)
        ->and($r->json('response.reactions'))->toBe([])
        ->and($r->json('response.stars'))->toBe([]);
});
