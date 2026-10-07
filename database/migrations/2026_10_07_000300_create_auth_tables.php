<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum personal access tokens. The legacy JWT had a 296,000,000-second
        // life (9.4 years), no revocation list and no record of which install held
        // it, which is why one leaked token was a permanent account takeover.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name'); // the device's own name, e.g. "iPhone 15"
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('platform', 16)->nullable(); // ios | android | web
            $table->unsignedBigInteger('install_id')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // One row per app install. The app registers on first launch and keeps a
        // token; only the token's SHA-256 is stored here. It holds what is needed
        // to push to that phone and nothing about the person.
        //
        // This replaces `accounts.fcm_token`, which is one token per *account* —
        // so a second phone silently took over the first one's notifications. The
        // security patch set has the same fix as `device_tokens`; this table is
        // that table with the fields the new app also needs.
        Schema::create('installs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('account_id')->nullable()->index();
            $table->string('platform', 16); // ios | android
            $table->string('name', 120)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 48)->nullable();
            $table->text('push_token')->nullable();
            $table->string('push_token_hash', 64)->nullable()->index();
            $table->timestamp('push_token_at')->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->string('timezone', 64)->default('UTC');
            $table->string('language', 16)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        // Single-use sign-in and verification codes. Only an HMAC of the code is
        // stored, keyed with APP_KEY, so a database copy does not hand anybody a
        // code. `legacy` codes are four digits because the old apps' field is.
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->index();
            $table->unsignedInteger('account_id')->nullable()->index();
            $table->string('purpose', 24)->default('login'); // login | email_change | legacy_login | password_reset
            $table->string('code_hmac', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        // One row per successful sign-in: when, how, from which platform. Used by
        // the console to answer "is this really the account owner?" and by the
        // account holder's own device list. No IP address and no user agent.
        Schema::create('sign_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id')->index();
            $table->string('method', 24); // code | google | apple | password | anonymous | legacy
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Replay protection for the Android HMAC scheme.
        //
        // `19/auth_checker.php` reads `X-Nonce` and throws it away; its own
        // comment says replay protection is still to do. `19/signature_checker.php`
        // in the same directory does it properly, with this table, and no endpoint
        // includes that file. The UNIQUE key is the whole mechanism: without it an
        // INSERT IGNORE accepts every replay.
        Schema::create('request_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('account_id');
            $table->string('device_id', 191);
            $table->string('nonce', 64);
            $table->unsignedInteger('ts_sec');
            $table->unique(['account_id', 'device_id', 'nonce'], 'uniq_nonce');
            $table->index('ts_sec', 'idx_ts');
        });
    }

    public function down(): void
    {
        foreach (['request_nonces', 'sign_ins', 'login_codes', 'installs', 'personal_access_tokens'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
