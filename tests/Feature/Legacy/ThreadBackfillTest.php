<?php

use App\Models\Account;
use App\Models\Comment;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Models\InstallSecret;
use App\Services\Legacy\LegacyJwt;

use function Tests\Support\signAs;

/*
 | The iOS back-fill. The live script declares REQUIRE_AUTH false and reads
 | `account_id` from the query string, so anybody could run one against
 | anybody — creating threads for them and re-pointing their comments.
 */

const AI = 1;
const STEP_DM = 13;
const BACKFILL_EPOCH = 1577836800;

beforeEach(function () {
    $make = function (): Account {
        $a = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
        $a->save();

        return $a;
    };

    // Account 1 is the assistant, so take the first id before anybody else.
    $this->assistant = $make();
    expect((int) $this->assistant->id)->toBe(AI);

    $this->account = $make();
    $this->partner = $make();
    $this->stranger = $make();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];

    // Two orphaned iOS messages between me and the partner, one each way.
    foreach ([[$this->account->id, $this->partner->id], [$this->partner->id, $this->account->id]] as [$from, $to]) {
        Comment::make()->forceFill([
            'thread_id' => 0, 'accountid' => $to, 'sponsorid' => $from,
            'step' => STEP_DM, 'recordid' => 0, 'byid' => $from,
            'comment' => 'An old message', 'tstamp' => time(), 'deleted' => 0, 'seen' => 0,
        ])->save();
    }
});

$backfill = fn (object $t, array $fields = []) => signAs(
    $t, '/api/v1/android/19/check_threads_connections_with_another_account.php', $fields,
);

it('gives the pair a thread and stamps their orphaned messages', function () use ($backfill) {
    $backfill($this)->assertOk();

    $thread = CommentThread::query()
        ->whereIn('id', CommentThreadSubscriber::query()
            ->where('account_id', $this->partner->id)->pluck('thread_id'))
        ->sole();

    expect($thread)->not->toBeNull();

    // Scoped to the two migrated messages: the assistant's greeting is also
    // a step 13 comment on this account.
    $stamped = Comment::query()->where('step', STEP_DM)
        ->where('comment', 'An old message')
        ->get();

    expect($stamped)->toHaveCount(2);
    foreach ($stamped as $comment) {
        expect((int) $comment->thread_id)->toBe((int) $thread->id);
    }
});

/*
 | A thread rebuilt from years-old comments is not new. Dating it today would
 | float every migrated conversation to the top of the list.
 */
it('dates a back-filled thread to the back-fill epoch, not today', function () use ($backfill) {
    $backfill($this)->assertOk();

    $thread = CommentThread::query()->where('created_by', $this->account->id)->first();

    expect((int) $thread->created)->toBe(BACKFILL_EPOCH);
});

it('gives everybody a thread with the assistant, and exactly one greeting', function () use ($backfill) {
    $backfill($this)->assertOk();

    $aiThreadId = CommentThreadSubscriber::query()
        ->where('account_id', AI)->value('thread_id');

    expect($aiThreadId)->not->toBeNull();

    $greetings = Comment::query()->where('thread_id', $aiThreadId)->where('byid', AI)->get();

    expect($greetings)->toHaveCount(1)
        ->and($greetings->first()->comment)->toBe('Hello, How can I help you?');
});

/*
 | s011 promises to run this once per account. It is written to be safe if
 | that promise is broken, and so is this.
 */
it('is idempotent', function () use ($backfill) {
    $backfill($this)->assertOk();
    $threadsAfterOne = CommentThread::query()->count();

    $backfill($this)->assertOk();
    $backfill($this)->assertOk();

    expect(CommentThread::query()->count())->toBe($threadsAfterOne)
        ->and(Comment::query()->where('byid', AI)->count())->toBe(1);
});

it('does not touch an account it has no messages with', function () use ($backfill) {
    $backfill($this)->assertOk();

    expect(CommentThreadSubscriber::query()->where('account_id', $this->stranger->id)->count())->toBe(0);
});

/*
 | The whole reason to reimplement it.
 */
it('will not run a back-fill against somebody else', function () use ($backfill) {
    $backfill($this, ['account_id' => $this->partner->id])->assertForbidden();

    expect(CommentThread::query()->count())->toBe(0);
});

it('refuses an unsigned request', function () {
    $this->postJson('/api/v1/android/19/check_threads_connections_with_another_account.php', [])
        ->assertUnauthorized();

    expect(CommentThread::query()->count())->toBe(0);
});

it('never creates a thread with oneself', function () use ($backfill) {
    Comment::make()->forceFill([
        'thread_id' => 0, 'accountid' => $this->account->id, 'sponsorid' => $this->account->id,
        'step' => STEP_DM, 'recordid' => 0, 'byid' => $this->account->id,
        'comment' => 'A row that should not exist', 'tstamp' => time(), 'deleted' => 0, 'seen' => 0,
    ])->save();

    $backfill($this)->assertOk();

    $mine = CommentThreadSubscriber::query()->where('account_id', $this->account->id)->pluck('thread_id');

    foreach ($mine as $threadId) {
        $others = CommentThreadSubscriber::query()->where('thread_id', $threadId)
            ->where('account_id', '!=', $this->account->id)->count();
        expect($others)->toBe(1);
    }
});
