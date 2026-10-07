<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The adopted tables, as this application believes them to be.
 *
 * **This is a test fixture and a reference, not a migration.** It is never run
 * against a real database: the tables below already exist in
 * `data_12steptoolkit` and have since 2019, and this application's first rule
 * is that it does not alter them.
 *
 * It exists because the test suite needs something to write into, and because
 * writing the reconstruction down somewhere runnable is the only way to find
 * out that it is wrong. If a test here passes and production does not, the
 * difference is in this file and it is worth a line in `docs/OPEN_QUESTIONS.md`.
 *
 * ## How much of this is known
 *
 * **Column names: solid.** Every one is read off an INSERT or UPDATE column
 * list in the live PHP, cross-checked between the two APIs.
 *
 * **Column types: inferred.** There is no schema dump of this database
 * anywhere — not in either script tree, not in the app repositories, not in the
 * security patch set. The types below come from `bind_param` type strings
 * (`i` or `s`, which cannot tell INT from TINYINT or VARCHAR from TEXT from
 * DATETIME) and from the casts the readers apply. One command settles it:
 *
 *     mysqldump --no-data --skip-add-drop-table --skip-comments data_12steptoolkit
 *
 * Three specific unknowns are listed in `docs/OPEN_QUESTIONS.md` and none of
 * them can be answered by reading code: whether `nights.sw8` exists, whether
 * `comment_thread_subscribers` is unique on `(thread_id, account_id)`, and
 * whether `comment_thread_subscribers.subscribed_at` is an INT or a DATETIME.
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
        self::accountDetails();
        self::records();
        self::installSecrets();
        self::appSettings();
    }

    private static function accounts(): void
    {
        Schema::create('accounts', function (Blueprint $t): void {
            $t->increments('id');
            $t->tinyInteger('verified')->default(0);
            $t->string('verificationcode')->default('');
            $t->string('email')->default('');      // deliberately not unique: it is not unique in production
            $t->string('phone_number')->default('');
            $t->string('hashed_password')->default('');
            $t->string('password')->default('');   // the legacy plaintext column
            $t->string('password_set')->default('');
            $t->tinyInteger('accounttype')->default(1);
            $t->string('sobrietydate')->default('');
            $t->string('sobrietytime')->default('');
            $t->integer('timestamp')->default(0);
            $t->tinyInteger('devicetype')->default(0);
            $t->tinyInteger('sociallogin')->default(0);
            $t->string('lastlogin')->default('');
            $t->integer('logincount')->default(0);
            $t->string('fbid')->default('');
            $t->string('googleid')->default('');
            $t->string('appleid')->default('');
            $t->integer('dailymoney')->default(0);
            $t->integer('dailyunits')->default(0);
            $t->integer('currency')->default(0);
            $t->tinyInteger('free_upgrade')->default(0);
            $t->string('step2')->default('');
            $t->string('step3')->default('');
            $t->string('step6')->default('');
            $t->string('step7')->default('');
            $t->tinyInteger('newsletter_subscribed')->default(0);
            $t->string('nickname')->default(' ');
            $t->integer('icon')->default(0);
            $t->tinyInteger('hp')->default(0);
            $t->string('fcm_token')->default('');
            $t->tinyInteger('subscribed')->default(0);
            $t->string('ip_address')->default('0');
            $t->integer('created_timestamp')->default(0);
            $t->integer('vcode_expires')->default(0);
            $t->dateTime('created')->nullable();
            // In production this is initialised to '0000-00-00 00:00:00'. SQLite
            // has no zero date, so the fixture uses null and `LegacyTable`
            // reads both as "never".
            $t->dateTime('modified')->nullable();
            $t->integer('on_boarding_completed_timestamp')->default(0);
            $t->tinyInteger('software_version')->default(1);
            $t->integer('last_login_tstamp')->default(0);
            $t->integer('deletion_timestamp')->default(0);
        });
    }

    private static function accountDetails(): void
    {
        Schema::create('account_details', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->index();
            $t->string('phonenumber')->default('');
            $t->string('countrycode')->default('');
            $t->string('country')->default('');
            $t->integer('lastseen')->default(0);
            // A string holding 'true'/'false' in some rows and 1/0 in others.
            $t->string('accept_new_sponsees')->default('false');
            $t->integer('accept_new_sponsor')->default(0);
            $t->string('accept_new_chat')->default('false');
            $t->integer('age')->default(-1);
            $t->integer('gender')->default(-1);
            $t->integer('profession')->default(-1);
            $t->text('about')->nullable();
            $t->string('language')->default('');
            $t->string('timezone')->default('');
            $t->string('notification_morning')->default('');
            $t->string('notification_night')->default('');
            $t->string('notification_hourly_start')->default('');
            $t->string('notification_hourly_end')->default('');
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });
    }

    private static function records(): void
    {
        Schema::create('inventories', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->index();
            $t->integer('inventoryforstep')->default(4);
            $t->integer('invtype')->default(1);
            $t->string('invtitle')->default('');
            $t->text('invdescription')->nullable();
            $t->string('affectsmyint')->default('');
            $t->string('affectsmy')->default('');
            $t->text('myfault')->nullable();
            $t->tinyInteger('shared')->default(0);
            $t->string('shareddate')->default('');
            $t->tinyInteger('apologyowed')->default(0);
            $t->tinyInteger('apologydone')->default(0);
            $t->string('apologydate')->default('');
            $t->text('apologynotes')->nullable();
            $t->string('timestamp')->default('');
            $t->integer('tstamp')->default(0);
            $t->tinyInteger('reviewed')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        Schema::create('amends', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->index();
            $t->string('amendstitle')->default('');
            $t->string('amendsfor')->default('');
            $t->tinyInteger('amendsdone')->default(0);
            $t->string('amendsdate')->default('');
            $t->string('timestamp')->default('');
            $t->text('amendsnotes')->nullable();
            $t->integer('tstamp')->default(0);
            $t->tinyInteger('reviewed')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        Schema::create('nights', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->index();
            foreach (['sw1', 'sw2', 'sw3', 'sw4', 'sw5', 'sw6', 'sw7', 'sw9', 'sw10', 'sw11', 'sw12'] as $sw) {
                $t->string($sw, 10)->default('No');
            }
            // `sw8` is deliberately absent: nobody has confirmed it exists in
            // production, and both clients omit it.
            for ($i = 1; $i <= 12; $i++) {
                $t->text('desc'.$i)->nullable();
            }
            $t->string('timestamp')->default('');
            $t->string('thedate')->default('');
            $t->integer('tstamp')->default(0);
            $t->integer('tdate')->default(0);
            $t->tinyInteger('reviewed')->default(0);
            $t->integer('for_date')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        Schema::create('mornings', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('accountid')->index();
            $t->string('icons')->default('1');
            $t->integer('q2')->default(1);
            $t->integer('q3')->default(1);
            $t->integer('q4')->default(1);
            $t->integer('q5')->default(1);
            $t->text('q6_notes')->nullable();
            $t->integer('tstamp')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
        });

        foreach (['journals', 'gratitudes'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->increments('id');
                $t->integer('accountid')->index();
                $t->text('description')->nullable();
                $t->string('timestamp')->default('');
                $t->integer('tstamp')->default(0);
                $t->dateTime('created')->nullable();
                $t->dateTime('modified')->nullable();
            });
        }
    }

    private static function installSecrets(): void
    {
        Schema::create('install_secrets', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('account_id');
            $t->string('device_id', 191);
            // BINARY(32) in production; a blob here. Never a string.
            $t->binary('secret');
            $t->tinyInteger('status')->default(1);
            $t->index(['account_id', 'device_id', 'status']);
        });
    }

    private static function appSettings(): void
    {
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
            $t->string('SALE_TITLE')->default('');
        });
    }
}
