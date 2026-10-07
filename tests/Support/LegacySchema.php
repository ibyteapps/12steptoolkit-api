<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The adopted tables, **transcribed from the real schema**.
 *
 * `docs/reference/legacy-schema.sql` is a structure-only dump of the live
 * `data_12steptoolkit` (MariaDB 11.4, taken 2026-10-07, no rows and no
 * credentials). Everything below is read off it rather than inferred, which is
 * a change worth knowing about: until that dump arrived, the column *names*
 * here were solid — read off the INSERT lists in the live PHP — but the *types*
 * were guesses from `bind_param` strings that cannot tell INT from TINYINT or
 * VARCHAR from TEXT.
 *
 * One of those guesses was wrong in a way that mattered. `install_secrets` has
 * `UNIQUE KEY uq_account_device (account_id, device_id)`; this fixture had a
 * plain index. So `bootstrap_secret.php`'s rotation — revoke the old row, insert
 * a new one — passed every test here and would have failed with a duplicate-key
 * error on the first real rotation in production. That is the entire argument
 * for keeping this file runnable.
 *
 * **This is a test fixture and a reference, never a migration.** It is never run
 * against a real database: these tables already exist and have since 2019, and
 * this application's first rule is that it does not alter them.
 *
 * ## Where SQLite cannot follow MariaDB
 *
 * Noted inline, and none of it changes behaviour under test:
 *
 *  * **zero dates.** Production initialises several `modified` columns to
 *    `'0000-00-00 00:00:00'`. SQLite has no such value, so those are nullable
 *    here and `LegacyTable::legacyDate()` reads both as "never".
 *  * **`binary(32)`** becomes a blob. Never a string, in either.
 *  * **generated columns, FULLTEXT and `ON UPDATE current_timestamp()`** are
 *    dropped; nothing in this application depends on the database doing them.
 *  * **charset is not reproducible.** `nights`, `mornings`, `appsettings` and
 *    `quotes` are **latin1** in production while the rest are utf8mb4, and the
 *    v19 connection is utf8mb4 — so a character outside latin1 written into a
 *    nightly review is turned into `?` on the way in. SQLite is UTF-8
 *    throughout and cannot show that. It is a live content-corruption bug, it is
 *    recorded in `docs/OPEN_QUESTIONS.md`, and no test here can catch it.
 */
final class LegacySchema
{
    public static function create(): void
    {
        // Idempotent: the suite calls this before every test, and an in-memory
        // database that has already been built must not be rebuilt.
        if (Schema::hasTable('accounts')) {
            return;
        }

        self::accounts();
        self::stepWork();
        self::settings();
        self::auth();
        self::social();
        self::commerce();
    }

    // ----------------------------------------------------------------- people

    private static function accounts(): void
    {
        Schema::create('accounts', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('verified')->default(0);
            $t->string('verificationcode', 50)->default('');
            // No unique index, and no index at all: duplicates exist, and every
            // email or social lookup is a full table scan. Both are faithful.
            $t->string('email', 100)->default('');
            $t->string('phone_number', 20)->default('');
            $t->string('hashed_password', 256)->default('');
            // varchar(20). The legacy plaintext column could never have held a
            // long password, which is worth knowing before anybody treats it as
            // a credential store.
            $t->string('password', 20)->default('');
            $t->string('password_set', 20)->default('');
            $t->integer('accounttype')->default(1);   // 1 = AA, 2 = NA
            $t->string('sobrietydate', 30)->default('');
            $t->string('sobrietytime', 5)->default('');
            // A varchar, not an int, despite the name.
            $t->string('timestamp', 100)->default('');
            $t->integer('devicetype')->default(1);    // 1 = Apple, 2 = Android, 3 = Web
            $t->integer('software_version')->default(1);
            $t->integer('last_login_tstamp')->default(0);
            $t->integer('sociallogin')->default(0);
            $t->string('lastlogin', 100)->default('');
            $t->integer('logincount')->default(0);
            $t->string('fbid', 40)->default('');
            $t->string('googleid', 40)->default('');
            $t->string('appleid', 255)->default('');
            $t->integer('dailymoney')->default(0);
            $t->integer('dailyunits')->default(0);
            $t->integer('currency')->default(0);
            $t->integer('free_upgrade')->default(0);  // "Free Upgrade From FB Email Glitch"
            // varchar(11) each: these hold a short marker, not the step's text.
            $t->string('step2', 11)->default('');
            $t->string('step3', 11)->default('');
            $t->string('step6', 11)->default('');
            $t->string('step7', 11)->default('');
            $t->integer('newsletter_subscribed')->default(0);
            $t->string('nickname', 50)->default(' ');
            $t->integer('icon')->default(0);
            $t->integer('hp')->default(0);
            $t->string('fcm_token', 255)->default('');
            $t->integer('subscribed')->default(0);
            $t->string('ip_address', 30)->default('0');
            $t->integer('created_timestamp')->default(0);
            $t->integer('on_boarding_completed_timestamp')->default(0);
            // An INT column with `DEFAULT current_timestamp()` in production.
            $t->integer('vcode_expires')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();     // '0000-00-00 00:00:00' in production
            $t->integer('deletion_timestamp')->default(0);

            $t->index(['on_boarding_completed_timestamp', 'created_timestamp'], 'ix_accounts_onboarding_created');
            $t->index('vcode_expires', 'ix_accounts_vcode_expiry');
            $t->index(['created', 'devicetype'], 'ix_accounts_created_device');
        });

        Schema::create('account_details', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid');
            $t->string('phonenumber', 20)->default('');
            $t->string('countrycode', 4)->default('');
            $t->string('country', 150)->default('');
            $t->integer('lastseen')->default(0);
            // A varchar holding 'true'/'false' — while `accept_new_sponsor` and
            // `accept_new_chat` beside it are ints. Three columns, two types,
            // one question.
            $t->string('accept_new_sponsees', 5)->default('false');
            $t->integer('accept_new_sponsor')->default(0);
            $t->integer('accept_new_chat')->default(0);
            $t->integer('age')->default(-1);
            $t->integer('gender')->default(-1);
            $t->integer('profession')->default(-1);
            $t->string('about', 100)->default('');
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
            $t->string('notification_morning', 5)->default('07:30');
            $t->string('notification_night', 5)->default('22:00');
            $t->string('notification_hourly_start', 5)->default('08:00');
            $t->string('notification_hourly_end', 5)->default('21:00');
            $t->string('timezone', 64)->default('');
            $t->string('language', 10)->default('');

            $t->index('accountid', 'ix_accountid');
            $t->index('lastseen', 'ix_lastseen');
            $t->index('language', 'ix_details_language');
        });
    }

    // -------------------------------------------------------------- step work

    private static function stepWork(): void
    {
        /*
         | `tstamp` is bigint everywhere in this group, and holds Unix
         | **seconds** — not milliseconds. The clients divide:
         | `F.getTStamp()` is `Date().time / 1_000L` (`extras/F.kt:607`), and
         | `MorningsFragment` and `Nights.kt` both do `/ 1000` too. So the
         | column is simply wider than it needs to be. Worth having written
         | down, because "bigint timestamp" reads as milliseconds to everybody
         | and comparing it against `comment_thread_subscribers.subscribed_at`
         | (an int, also seconds) on that assumption would widen every chat
         | visibility window by a factor of a thousand.
         |
         | Note the indexes, or the lack of them: `journals`, `gratitudes` and
         | `nights` have one on (account, time); `inventories`, `amends` and
         | `mornings` have nothing but the primary key, so every read of
         | somebody's Fourth Step is a full table scan.
         */
        Schema::create('inventories', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->default(1);
            $t->integer('inventoryforstep')->default(10);  // 4 or 10
            $t->integer('invtype')->default(1);            // 1 resentment, 2 fear, 3 harm, 4 sex
            $t->string('invtitle', 100)->default('');
            $t->string('invdescription', 2000)->default('');
            $t->string('affectsmyint', 100)->default('');
            $t->string('affectsmy', 100)->default('');
            $t->string('myfault', 2000)->default('');
            $t->integer('shared')->default(0);             // shown to my sponsor
            $t->string('shareddate', 20)->default('');
            $t->integer('apologyowed')->default(0);
            $t->integer('apologydone')->default(0);
            $t->string('apologydate', 20)->default('');
            $t->string('apologynotes', 2000)->default('');
            $t->string('timestamp', 100)->default('');
            $t->bigInteger('tstamp')->default(0);
            $t->integer('reviewed')->default(0);           // and see the `reviewed` table
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        Schema::create('amends', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid');
            $t->string('amendstitle', 50)->default('');
            $t->string('amendsfor', 1000)->default('');
            $t->integer('amendsdone')->default(0);
            $t->string('amendsdate', 20)->default('');
            $t->string('timestamp', 100)->default('');
            $t->string('amendsnotes', 1000)->default('');
            $t->bigInteger('tstamp')->default(0);
            $t->integer('reviewed')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        Schema::create('nights', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid');
            // Eleven switches. `sw8` **does not exist** — confirmed from the
            // real schema, not inferred. There are twelve `desc` columns, so
            // question 8 has an answer and no switch.
            foreach (['sw1', 'sw2', 'sw3', 'sw4', 'sw5', 'sw6', 'sw7', 'sw9', 'sw10'] as $sw) {
                $t->string($sw, 3)->default('');          // NOT NULL, no default in production
            }
            $t->string('sw11', 3)->default('No');
            $t->string('sw12', 3)->default('No');
            for ($i = 1; $i <= 12; $i++) {
                $t->string('desc'.$i, 2000)->default('');
            }
            $t->string('timestamp', 100)->default('');
            $t->string('thedate', 50)->default('');
            $t->bigInteger('tstamp')->default(0);
            $t->bigInteger('tdate')->default(0);
            $t->integer('reviewed')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();          // zero date in production
            $t->bigInteger('for_date')->default(0);        // "Added 2025_01_02 for Web"

            // Ordered by `tdate`, not `tstamp` — the only collection whose index
            // disagrees with `StepRecord::orderColumn()`.
            $t->index(['accountid', 'tdate'], 'ix_nights_account_date');
        });

        Schema::create('mornings', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->default(0);
            $t->string('icons', 255)->default('1');
            // Default 0, not 1. A morning saved with no answers reads as zeros.
            $t->integer('q2')->default(0);
            $t->integer('q3')->default(0);
            $t->integer('q4')->default(0);
            $t->integer('q5')->default(0);
            $t->string('q6_notes', 2000)->default('');
            $t->bigInteger('tstamp')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        foreach (['journals' => true, 'gratitudes' => false] as $table => $modifiedHasDefault) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->increments('id');
                $t->integer('accountid');
                $t->string('description', 5000)->default('');
                $t->string('timestamp', 100)->default('');
                $t->bigInteger('tstamp')->default(0);
                $t->dateTime('created')->nullable();
                // `journals.modified` defaults to now, `gratitudes.modified` to
                // the zero date. Same table shape, different history.
                $t->dateTime('modified')->nullable();
                $t->index(['accountid', 'tstamp'], 'ix_'.$table.'_account_time');
            });
        }

        // Who reviewed what, beside the `reviewed` flag on each record. `type`
        // is what makes `inventory_id` polymorphic across the collections.
        Schema::create('reviewed', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('inventory_id')->default(0);
            $t->integer('sponsorid')->default(0);
            $t->integer('type')->default(0);
            $t->integer('tstamp')->default(0);
        });

        Schema::create('step12', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid');
        });
    }

    // --------------------------------------------------------------- settings

    private static function settings(): void
    {
        // The one-row table of limits both apps read.
        Schema::create('appsettings', function (Blueprint $t): void {
            $t->increments('id');
            foreach ([
                'MAX_TITLE', 'MAX_TITLE_PRO', 'MAX_DESCRIPTION', 'MAX_DESCRIPTION_PRO',
                'MAX_NOTES', 'MAX_NOTES_PRO', 'MAX_MEETING_SEARCHES', 'MAX_MEETING_SEARCHES_PRO',
                'MAX_GROUPS_FREE', 'TIME_BETWEEN_ADS', 'TAPS_BETWEEN_ADS',
                'TIME_BETWEEN_APP_OPEN', 'SPONSOR_COUNT', 'SALE_START', 'SALE_END',
            ] as $column) {
                $t->integer($column)->default(0);
            }
            $t->string('SALE_TITLE', 50)->default('');
        });

        // A *second* settings table, key/value, added later. Nothing in either
        // shipped client reads it as far as I can tell; it is reproduced so that
        // `app:check` can see it and so nobody re-creates it by accident.
        Schema::create('app_settings', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('setting_key', 50)->default('');
            $t->string('version_added', 10)->default('');
            $t->string('description', 200)->default('');
            $t->integer('setting_value')->default(0);
            $t->text('setting_description')->nullable();
        });

        // The sale, in a table of its own as well as in `appsettings.SALE_*`.
        Schema::create('sale', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('sale_title', 100)->default('');
            $t->bigInteger('sale_start_ts')->default(0);
            $t->bigInteger('sale_end_ts')->default(0);
            $t->dateTime('sale_start_dt')->nullable();
            $t->dateTime('sale_end_dt')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        // Maintenance mode and the minimum version, read at launch.
        Schema::create('serverstatus', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('MaintenanceMode')->default(0);
            $t->string('MaintenanceNotice', 200)->default('');
            $t->integer('latestversion')->default(0);
        });

        Schema::create('builds', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('build_type');                 // 1 = iOS, 2 = Android
            $t->text('version_name')->nullable();
            $t->integer('version_code')->default(0);
            $t->integer('expires')->default(0);
        });

        Schema::create('quotes', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('description', 2000)->default('');
        });
    }

    // ------------------------------------------------------------------- auth

    private static function auth(): void
    {
        /*
         | Added by the 2024 Android security patch set — and it is **in
         | production**, which settles the biggest open question: the patch set's
         | schema did ship, whatever happened to the rest of it.
         |
         | The UNIQUE on (account_id, device_id) is the part that matters: one
         | live signing key per install, and a rotation has to replace the row
         | rather than add one beside it.
         */
        Schema::create('install_secrets', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->string('device_id', 128);
            $t->binary('secret');                      // binary(32) in production
            $t->integer('status')->default(1);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();

            $t->unique(['account_id', 'device_id'], 'uq_account_device');
            $t->index('account_id', 'ix_install_secrets_account');
        });

        // The per-device push table, which also shipped. It replaces
        // `accounts.fcm_token` — one token per account, so a second phone
        // silently took over the first one's notifications.
        Schema::create('devices', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id')->default(0);
            $t->integer('device_type');                // 1 Apple, 2 Android, 3 Web
            $t->string('fcm_code', 200)->default('');
            $t->integer('login_count')->default(0);
            $t->string('random_device_token', 50)->default('');
            $t->dateTime('random_device_token_created')->nullable();
            $t->dateTime('fcm_updated_time_stamp')->nullable();
            $t->integer('fcm_update_count')->default(0);
        });

        Schema::create('password_resets', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('account_id');
            $t->char('reset_code', 32);
            $t->unsignedInteger('expires_at');
            $t->dateTime('created_at')->nullable();
            $t->index('reset_code');
            $t->index('account_id');
        });

        Schema::create('reminder_subscriptions', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->string('type', 16);                    // enum MORNING|NIGHT|HOURLY
            $t->char('hhmm', 5);
            $t->unsignedTinyInteger('days_mask')->default(127);
            $t->string('timezone', 64)->default('');
            $t->string('language', 10)->default('en');
            $t->boolean('enabled')->default(true);
            $t->dateTime('snoozed_until')->nullable();
            $t->dateTime('last_fired_at')->nullable();
            $t->dateTime('next_fire_at')->nullable();
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
            $t->unique(['account_id', 'type', 'hhmm'], 'uq_account_type_hhmm');
            $t->index(['enabled', 'next_fire_at'], 'idx_due');
        });

        Schema::create('notification_texts', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('type', 16);                    // enum MORNING|NIGHT|HOURLY
            $t->string('language', 10)->default('en');
            $t->text('description');
            // `description_hash` is a STORED generated column in production
            // (unhex(md5(description))) carrying the unique key, and there is a
            // FULLTEXT index on `description`. SQLite has neither.
            $t->dateTime('last_used')->nullable();
            $t->integer('times_used')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
            $t->index(['type', 'language', 'times_used', 'last_used'], 'ix_pick_order');
        });
    }

    // ----------------------------------------------------------------- social

    private static function social(): void
    {
        Schema::create('sponsors', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('sponsorid');
            $t->integer('sponseeid');
            $t->integer('status')->default(0);         // 0 pending 1 accepted 2 rejected 3 deleted 4 blocked
            $t->integer('relationship_direction');     // 1 sponsor→sponsee, 2 sponsee→sponsor
            $t->bigInteger('requested_tstamp')->default(0);
            $t->bigInteger('accepted_tstamp')->default(0);
            // "also used to signify accepted by id if status = 0" — one column,
            // two meanings, depending on another column.
            $t->bigInteger('rejected_tstamp')->default(0);
            $t->bigInteger('deleted_tstamp')->default(0);
            $t->bigInteger('blocked_tstamp')->default(0);
            $t->integer('rejected_by')->default(0);
            $t->integer('blocked_by')->default(0);
            $t->dateTime('modified')->nullable();
            $t->index(['sponseeid', 'status'], 'ix_sponsors_lookup_active');
            $t->index(['sponsorid', 'status'], 'ix_sponsors_lookup_sponsor');
        });

        Schema::create('comment_threads', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('title', 100)->default(' ');
            $t->string('description', 255)->default(' ');
            $t->integer('icon')->default(0);
            $t->integer('created_by');
            $t->integer('created')->default(0);        // an int here, a datetime elsewhere
            $t->boolean('is_group')->default(false);
            $t->boolean('is_public')->default(false);
            $t->boolean('accepting_new_members')->default(true);
            $t->unsignedSmallInteger('max_members')->default(100);
            $t->boolean('is_muted')->default(false);
            $t->boolean('is_deleted')->default(false);
            $t->dateTime('modified')->nullable();
            $t->index(['is_group', 'is_public'], 'ix_threads_is_group_public');
        });

        Schema::create('comments', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('thread_id')->default(0);
            $t->integer('accountid')->default(0);
            $t->integer('sponsorid')->default(0);
            $t->integer('step')->default(0);
            $t->integer('recordid')->default(0);
            $t->integer('byid')->default(0);
            $t->string('comment', 2000)->default('');
            $t->bigInteger('tstamp')->default(0);
            $t->integer('deleted')->default(0);
            $t->integer('seen')->default(0);
            $t->dateTime('time_sent')->nullable();
            $t->dateTime('modified')->nullable();
            $t->index(['thread_id', 'tstamp'], 'ix_comments_thread_tstamp');
            $t->index(['accountid', 'tstamp'], 'ix_comments_account_tstamp');
        });

        /*
         | **UNIQUE (thread_id, account_id)** — the second open question, now
         | answered. So joining a thread is an upsert on that pair, not an
         | insert: an insert would be a duplicate-key error on the second reply,
         | and a check-then-insert would race. `subscribed_at` is an INT (a Unix
         | timestamp) and nullable, which was the third question.
         */
        Schema::create('comment_thread_subscribers', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('thread_id');
            $t->integer('account_id');
            $t->integer('subscribed_at')->nullable();
            $t->boolean('is_subscribed')->default(true);
            $t->boolean('is_deleted')->default(false);
            $t->boolean('is_admin')->default(false);
            $t->boolean('is_typing')->default(false);
            $t->dateTime('last_typing_at')->nullable();
            $t->boolean('is_muted')->default(false);
            $t->dateTime('modified')->nullable();
            $t->unique(['thread_id', 'account_id'], 'uq_thread_account');
            $t->index(['account_id', 'is_deleted', 'is_subscribed'], 'ix_subscribers_active');
        });

        Schema::create('comment_thread_subscriber_history', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('thread_id');
            $t->integer('account_id');
            $t->boolean('is_subscribed');
            $t->boolean('is_deleted')->default(false);
            $t->unsignedInteger('changed_tstamp');
        });

        Schema::create('comment_reactions', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('comment_id');
            $t->integer('account_id');
            $t->string('reaction', 50);
            $t->boolean('is_deleted')->default(false);
            $t->dateTime('modified')->nullable();
            $t->unique(['comment_id', 'account_id', 'reaction'], 'uniq_comment_reaction');
        });

        Schema::create('comment_receipts', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->integer('comment_id');
            $t->unsignedBigInteger('time_delivered')->default(0);
            $t->unsignedBigInteger('time_read')->default(0);
            $t->dateTime('modified')->nullable();
            $t->unique(['account_id', 'comment_id'], 'uq_receipt_account_comment');
        });

        Schema::create('comment_stars', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('comment_id');
            $t->integer('account_id');
            $t->boolean('is_deleted')->default(false);
            $t->dateTime('modified')->nullable();
            $t->unique(['account_id', 'comment_id'], 'uq_star_account_comment');
        });

        Schema::create('group_invites', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('thread_id');
            $t->integer('created_by');
            $t->char('code', 32);
            $t->unsignedTinyInteger('role')->default(0);
            $t->unsignedInteger('max_uses')->default(10);
            $t->unsignedInteger('uses')->default(0);
            $t->dateTime('expires_at')->nullable();
            $t->boolean('revoked')->default(false);
            $t->dateTime('created')->nullable();
            $t->unique('code', 'uq_group_invites_code');
        });

        Schema::create('blocked_users', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('blocker_id');
            $t->integer('blocked_id');
            $t->text('reason')->nullable();
            $t->integer('reason_code')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('modified')->nullable();
            $t->boolean('is_deleted')->default(false);
            $t->unique(['blocker_id', 'blocked_id'], 'uq_block_pair');
        });

        /*
         | The old system's only moderation surface was `19/admin/reported.php`,
         | an unauthenticated HTML page that printed this table. The table itself
         | is reasonable — it has a status and admin notes.
         */
        Schema::create('reported_users', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('reporter_id');
            $t->integer('reported_id')->nullable();
            $t->integer('reported_thread_id')->nullable();
            $t->text('reason')->nullable();
            $t->integer('reason_code')->default(0);
            $t->string('status', 20)->default('pending');
            $t->text('admin_notes')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->boolean('is_deleted')->default(false);
            $t->index('status', 'idx_status');
        });

        Schema::create('icons', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->string('image_url', 255);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
            $t->unique(['account_id', 'image_url'], 'uq_icons_account_url');
        });

        Schema::create('lastseen_requests', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid');
            $t->integer('forid');
            $t->integer('timestamp');
        });

        Schema::create('user_online_notifications', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->integer('sent_to_account_id');
            $t->dateTime('last_sent_at')->nullable();
        });

        Schema::create('meeting_locations', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->integer('location_id');
        });
    }

    // --------------------------------------------------------------- commerce

    private static function commerce(): void
    {
        /*
         | Three order tables, none of which this application writes.
         | `Entitlement` and `store_subscriptions` answer "is this person
         | premium now"; these answer "what did they buy", with no status, no
         | expiry and no webhook — which is why they cannot answer the first
         | question and are left alone.
         |
         | `price` is a varchar. Money as text, in whatever the store sent.
         */
        Schema::create('orders', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid');
            $t->string('orderid', 30)->default('');
            $t->string('timestamp', 100)->default('');
            $t->string('sku', 50)->default('');
        });

        foreach (['subscription_orders', 'sponsee_orders'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->increments('id');
                $t->integer('accountid');
                $t->string('orderid', 30)->default('');
                $t->bigInteger('tstamp')->default(0);
                $t->string('sku', 50)->default('');
                $t->integer('months')->default(0);
                $t->integer('quantity')->default(0);
                $t->string('price', 100)->default('');
                $t->index('tstamp', 'ix_'.$table.'_tstamp');
            });
        }

        Schema::create('sponsee_order_users', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('sponsee_order_id')->default(0);
            $t->integer('sponseeid')->default(0);
            $t->bigInteger('tstamp')->default(0);
        });
    }
}
