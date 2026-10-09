<?php

use App\Models\Account;
use App\Models\Comment;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Services\Chat\CommentNotifier;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\DB;

/*
 | Comments were being stored and nobody was being told, so a sponsor's reply
 | sat in the database until the sponsee happened to open the app.
 */

class CollectingSender implements PushSender
{
    /** @var array<int, array{tokens: array<int, string>, message: PushMessage}> */
    public array $sent = [];

    public function send(array $tokens, PushMessage $message): int
    {
        $this->sent[] = ['tokens' => $tokens, 'message' => $message];

        return count($tokens);
    }
}

beforeEach(function () {
    $this->sender = new CollectingSender;
    $this->app->instance(PushSender::class, $this->sender);

    $make = function (string $nickname, string $token): Account {
        $a = Account::make()->forceFill([
            'nickname' => $nickname, 'created' => now(), 'fcm_token' => $token,
            'email' => $nickname.'@example.com',
        ]);
        $a->save();

        return $a;
    };

    $this->author = $make('Jo', 'jo-device');
    $this->reader = $make('Sam', 'sam-device');

    $thread = new CommentThread;
    $thread->forceFill([
        'title' => '', 'description' => '', 'icon' => 0,
        'created_by' => $this->author->id, 'created' => time(), 'is_group' => 0, 'is_muted' => 0,
    ])->save();
    $this->thread = $thread;

    $this->subscribe = function (Account $a, array $overrides = []) use ($thread): void {
        CommentThreadSubscriber::query()->insert(array_merge([
            'thread_id' => $thread->id, 'account_id' => $a->id,
            'subscribed_at' => time(), 'is_subscribed' => 1,
            'is_deleted' => 0, 'is_admin' => 0, 'is_muted' => 0,
        ], $overrides));
    };

    ($this->subscribe)($this->author);
    ($this->subscribe)($this->reader);

    $this->comment = new Comment;
    $this->comment->forceFill([
        'thread_id' => $thread->id, 'accountid' => $this->author->id,
        'sponsorid' => $this->reader->id, 'step' => 13, 'recordid' => 0,
        'byid' => $this->author->id, 'comment' => 'Something private about my Step Four',
        'tstamp' => time(), 'deleted' => 0, 'seen' => 0,
    ])->save();

    $this->notify = fn () => app(CommentNotifier::class)->notify($this->comment, $this->author);
});

it('tells the other person in the thread', function () {
    expect(($this->notify)())->toBe(1)
        ->and($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0]['tokens'])->toBe(['sam-device']);
});

/*
 | The legacy script builds the body as a nickname plus a fixed phrase and
 | never the thing written. A push payload goes through Google's servers and
 | sits on a lock screen; a sponsor conversation belongs in neither.
 */
it('never puts the message in the notification', function () {
    ($this->notify)();

    $message = $this->sender->sent[0]['message'];

    expect($message->title)->toBe('Jo')
        ->and($message->body)->toBe('sent you a message')
        ->and($message->body)->not->toContain('Step Four')
        ->and(json_encode($message->data))->not->toContain('Step Four');
});

it('does not tell the author', function () {
    ($this->notify)();

    foreach ($this->sender->sent as $sent) {
        expect($sent['tokens'])->not->toContain('jo-device');
    }
});

it('skips somebody who has muted the thread', function () {
    CommentThreadSubscriber::query()
        ->where('thread_id', $this->thread->id)
        ->where('account_id', $this->reader->id)
        ->update(['is_muted' => 1]);

    expect(($this->notify)())->toBe(0);
});

it('skips somebody who has left the thread', function () {
    CommentThreadSubscriber::query()
        ->where('thread_id', $this->thread->id)
        ->where('account_id', $this->reader->id)
        ->update(['is_subscribed' => 0]);

    expect(($this->notify)())->toBe(0);
});

it('skips a block, whichever way round it is', function () {
    DB::table('blocked_users')->insert([
        'blocker_id' => $this->reader->id, 'blocked_id' => $this->author->id,
        'reason_code' => 1, 'is_deleted' => 0,
    ]);

    expect(($this->notify)())->toBe(0);

    DB::table('blocked_users')->truncate();
    DB::table('blocked_users')->insert([
        'blocker_id' => $this->author->id, 'blocked_id' => $this->reader->id,
        'reason_code' => 1, 'is_deleted' => 0,
    ]);

    expect(($this->notify)())->toBe(0);
});

it('skips an erased account', function () {
    DB::table('accounts')->where('id', $this->reader->id)->update(['email' => 'DELETED']);

    expect(($this->notify)())->toBe(0);
});

it('says Someone when the author has no nickname', function () {
    DB::table('accounts')->where('id', $this->author->id)->update(['nickname' => ' ']);

    app(CommentNotifier::class)->notify($this->comment, $this->author->fresh());

    expect($this->sender->sent[0]['message']->title)->toBe('Someone');
});

it('tells everybody in a group thread except the author', function () {
    $third = Account::make()->forceFill([
        'nickname' => 'Alex', 'created' => now(), 'fcm_token' => 'alex-device',
        'email' => 'alex@example.com',
    ]);
    $third->save();
    ($this->subscribe)($third);

    expect(($this->notify)())->toBe(2);
});
