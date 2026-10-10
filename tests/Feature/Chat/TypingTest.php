<?php

use App\Models\Account;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Models\InstallSecret;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\DB;

use function Tests\Support\signAs;

/*
 | `update_is_typing.php` reads `account_id` from the body and updates
 | `comment_thread_subscribers` by (thread_id, account_id) with no check that
 | the caller is either of them — then pushes it to the thread. So anybody
 | could make anybody appear to be typing in a conversation they are not in.
 */

class TypingSender implements PushSender
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
    $this->sender = new TypingSender;
    $this->app->instance(PushSender::class, $this->sender);

    $make = function (string $token = ''): Account {
        $a = Account::make()->forceFill(['nickname' => ' ', 'created' => now(), 'fcm_token' => $token]);
        $a->save();

        return $a;
    };

    $this->account = $make();
    $this->listener = $make('listener-device');
    $this->stranger = $make('stranger-device');

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = $this->account->createToken('Test', ['*'], now()->addDay())->plainTextToken;

    $t = new CommentThread;
    $t->forceFill([
        'title' => 'Tuesday group', 'description' => ' ', 'created_by' => $this->account->id,
        'created' => time(), 'is_group' => 1, 'is_public' => 0,
    ])->save();
    $this->thread = $t;

    $join = function (int $accountId, array $extra = []): void {
        $s = new CommentThreadSubscriber;
        $s->forceFill(array_merge([
            'thread_id' => $this->thread->id, 'account_id' => $accountId,
            'subscribed_at' => time() - 3600, 'is_subscribed' => 1, 'is_deleted' => 0, 'is_admin' => 0,
        ], $extra))->save();
    };
    $join((int) $this->account->id);
    $join((int) $this->listener->id);
    $this->join = $join;

    $this->type = fn (array $fields = []) => signAs($this, '/api/v1/android/19/update_is_typing.php', array_merge([
        'thread_id' => $this->thread->id,
        'account_id' => $this->account->id,
        'is_typing' => 1,
    ], $fields));
});

it('records the typing flag and tells the rest of the thread', function () {
    ($this->type)()->assertOk()->assertJsonPath('response.is_typing', 1);

    $row = DB::table('comment_thread_subscribers')
        ->where('thread_id', $this->thread->id)->where('account_id', $this->account->id)->first();

    expect((int) $row->is_typing)->toBe(1)
        ->and($row->last_typing_at)->not->toBeNull();

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0]['tokens'])->toBe(['listener-device'])
        ->and($this->sender->sent[0]['message']->table())->toBe('comment_thread_subscribers')
        ->and($this->sender->sent[0]['message']->data['action'])->toBe('TYPING');
});

it('sends it silently, because a typing dot is not a notification', function () {
    ($this->type)()->assertOk();

    expect($this->sender->sent[0]['message']->isSilent())->toBeTrue();
});

it('clears the flag', function () {
    ($this->type)()->assertOk();
    ($this->type)(['is_typing' => 0])->assertOk()->assertJsonPath('response.is_typing', 0);

    expect((int) DB::table('comment_thread_subscribers')
        ->where('account_id', $this->account->id)->value('is_typing'))->toBe(0);
});

it('refuses somebody who is not in the thread', function () {
    DB::table('comment_thread_subscribers')
        ->where('account_id', $this->account->id)->update(['is_deleted' => 1]);

    ($this->type)()->assertStatus(403);

    expect($this->sender->sent)->toBeEmpty();
});

it('will not let the body claim to be somebody else', function () {
    // The live script would have updated the stranger's row and pushed it.
    ($this->type)(['account_id' => $this->stranger->id])->assertStatus(403);

    expect($this->sender->sent)->toBeEmpty();
});

it('still tells somebody who has muted the thread', function () {
    DB::table('comment_thread_subscribers')
        ->where('account_id', $this->listener->id)->update(['is_muted' => 1]);

    // Muting is about being interrupted. A typing indicator draws on a screen
    // somebody is already looking at.
    ($this->type)()->assertOk();

    expect($this->sender->sent)->toHaveCount(1);
});

it('does not tell somebody on either side of a block', function () {
    ($this->join)((int) $this->stranger->id);
    DB::table('blocked_users')->insert([
        'blocker_id' => $this->stranger->id, 'blocked_id' => $this->account->id,
        'is_deleted' => 0, 'created_at' => now(),
    ]);

    ($this->type)()->assertOk();

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0]['tokens'])->toBe(['listener-device']);
});
