<?php

use App\Models\Account;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Models\Icon;
use App\Models\InstallSecret;
use App\Models\Sponsor;
use Illuminate\Http\UploadedFile;

use function Tests\Support\signAs;

/**
 * Profile pictures.
 *
 * `upload_icon.php` takes the file's type from a request header and its
 * extension from the client's own filename, with no check that the bytes are an
 * image at all — so `evil.php` sent as `image/png` is saved as `<id>.php`. The
 * only thing stopping that being remote code execution is that the icons
 * directory happens to sit outside the web root. Most of this file is about
 * that.
 */
beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/icons-'.bin2hex(random_bytes(4));
    mkdir($this->directory, 0755, true);
    config(['toolkit.icons.path' => $this->directory]);

    $this->account = Account::make()->forceFill(['nickname' => 'Jo', 'created' => now()]);
    $this->account->save();
    $this->other = Account::make()->forceFill(['nickname' => 'Sam', 'created' => now()]);
    $this->other->save();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = $this->account->createToken('Test', ['*'], now()->addDay())->plainTextToken;
});

afterEach(function () {
    foreach (glob($this->directory.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->directory);
});

/** A real image, of the given type. */
function picture(string $type = 'png', string $name = 'me.png'): UploadedFile
{
    $image = imagecreatetruecolor(8, 8);
    $path = tempnam(sys_get_temp_dir(), 'pic');

    match ($type) {
        'jpg' => imagejpeg($image, $path),
        'webp' => imagewebp($image, $path),
        default => imagepng($image, $path),
    };
    imagedestroy($image);

    return new UploadedFile($path, $name, 'image/'.$type, null, true);
}

/** Something that is not an image, however it describes itself. */
function notAPicture(string $name, string $claimedType, string $contents = "<?php echo 'hello'; ?>"): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $claimedType, null, true);
}

it('refuses an upload with no image', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', [])
        ->assertStatus(400);
});

it('refuses an upload with no token, which the old script did not', function () {
    // `upload_icon.php` sets REQUIRE_AUTH false, so it was an unauthenticated
    // file write for any account id.
    $this->post('/api/v1/android/19/upload_icon.php', ['image' => picture()])
        ->assertStatus(401);

    expect(Icon::query()->count())->toBe(0);
})->skip(! function_exists('imagepng'), 'GD is not available');

it('stores a picture and points the account at it', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', ['image' => picture()])
        ->assertOk();

    $icon = Icon::query()->sole();

    expect($response->json('response.icon_id'))->toBe((string) $icon->id)
        ->and($icon->image_url)->toBe($icon->id.'.png')
        ->and(is_file($this->directory.'/'.$icon->image_url))->toBeTrue()
        // `accounts.icon` above 40 is an `icons.id`.
        ->and((int) $this->account->fresh()->icon)->toBe((int) $icon->id);
})->skip(! function_exists('imagepng'), 'GD is not available');

it('names the file from the bytes, not from what the client called it', function () {
    // The whole hole: the old script would save this as `<id>.php`.
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', [
            'image' => picture('png', 'evil.php'),
        ])->assertOk();

    $icon = Icon::query()->sole();

    expect($icon->image_url)->toEndWith('.png')
        ->and($icon->image_url)->not->toContain('php')
        ->and($icon->image_url)->not->toContain('evil')
        ->and(glob($this->directory.'/*.php'))->toBe([]);
})->skip(! function_exists('imagepng'), 'GD is not available');

it('refuses a file that is not an image, whatever it claims to be', function () {
    // `image/png` in the header was the whole of the old allow-list.
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', [
            'image' => notAPicture('shell.png', 'image/png'),
        ])->assertStatus(415);

    expect(Icon::query()->count())->toBe(0)
        ->and(glob($this->directory.'/*'))->toBe([]);
});

it('refuses a picture larger than the cap', function () {
    config(['toolkit.icons.max_bytes' => 10]);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', ['image' => picture()])
        ->assertStatus(413);

    expect(Icon::query()->count())->toBe(0);
})->skip(! function_exists('imagepng'), 'GD is not available');

it('replaces the old picture and its file', function () {
    $post = fn ($file) => $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', ['image' => $file]);

    $post(picture('png'))->assertOk();
    $first = Icon::query()->sole()->image_url;

    // A different type, which is where the old script leaked a file: it deletes
    // the old one using the *new* extension against the old id.
    $post(picture('jpg', 'me.jpg'))->assertOk();

    expect(Icon::query()->count())->toBe(1)
        ->and(is_file($this->directory.'/'.$first))->toBeFalse()
        ->and(Icon::query()->sole()->image_url)->toEndWith('.jpg')
        ->and(glob($this->directory.'/*'))->toHaveCount(1);
})->skip(! function_exists('imagejpeg'), 'GD is not available');

it('deletes a picture and resets the account', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', ['image' => picture()])->assertOk();

    signAs($this, '/api/v1/android/19/delete_icon.php')->assertOk();

    expect(Icon::query()->count())->toBe(0)
        ->and((int) $this->account->fresh()->icon)->toBe(0)
        ->and(glob($this->directory.'/*'))->toBe([]);
})->skip(! function_exists('imagepng'), 'GD is not available');

// --------------------------------------------------------------- who may look

it('streams your own picture', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/upload_icon.php', ['image' => picture()])->assertOk();

    $r = signAs($this, '/api/v1/android/19/icon/'.$this->account->id, [], ['method' => 'GET']);

    $r->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('private');
})->skip(! function_exists('imagepng'), 'GD is not available');

it('refuses a stranger\'s picture', function () {
    $icon = new Icon;
    $icon->forceFill(['account_id' => $this->other->id, 'image_url' => 'x.png'])->save();

    signAs($this, '/api/v1/android/19/icon/'.$this->other->id, [], ['method' => 'GET'])
        ->assertStatus(403);
});

it('allows a picture to somebody you have a relationship with', function () {
    (new Sponsor)->forceFill([
        'sponsorid' => $this->other->id, 'sponseeid' => $this->account->id,
        'status' => Sponsor::ACCEPTED, 'relationship_direction' => 1, 'requested_tstamp' => time(),
    ])->save();

    // 404 rather than 403: allowed to look, nothing there.
    signAs($this, '/api/v1/android/19/icon/'.$this->other->id, [], ['method' => 'GET'])
        ->assertStatus(404);
});

it('allows a picture to somebody in the same thread', function () {
    $t = new CommentThread;
    $t->forceFill(['title' => 'g', 'description' => ' ', 'created_by' => 1, 'created' => time(), 'is_group' => 1])->save();

    foreach ([$this->account->id, $this->other->id] as $id) {
        (new CommentThreadSubscriber)->forceFill([
            'thread_id' => $t->id, 'account_id' => $id, 'subscribed_at' => time(),
            'is_subscribed' => 1, 'is_deleted' => 0,
        ])->save();
    }

    signAs($this, '/api/v1/android/19/icon/'.$this->other->id, [], ['method' => 'GET'])
        ->assertStatus(404);
});
