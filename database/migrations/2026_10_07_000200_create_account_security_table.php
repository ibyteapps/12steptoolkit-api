<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything this application needs to know about an account that the legacy
 * `accounts` table does not already hold.
 *
 * ## Why this is a separate table
 *
 * **This application never alters a legacy table.** `accounts`,
 * `account_details`, `inventories`, `amends`, `nights`, `mornings`, `journals`,
 * `gratitudes`, `sponsors`, `comments` and the rest are adopted exactly as they
 * are, and every new column lives in a table of this application's own.
 *
 * The reason is the same one that governs the Flutter app's SQLite schema: the
 * cutover has to be reversible. If the Laravel application has to be rolled back
 * and the old PHP scripts pointed at this database again, they must find the
 * tables they wrote, unchanged, with no column they do not understand and no
 * type they cannot write. `accounts.modified` is initialised to
 * `'0000-00-00 00:00:00'` by the old code; a well-meaning `->change()` on that
 * column would break every legacy insert on a modern MySQL. So: nothing is
 * changed, and the price is one join.
 *
 * It also means this table can be dropped and the legacy system is intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_security', function (Blueprint $table) {
            // Not an auto-increment of its own: the account id *is* the key, so
            // there can never be two rows for one person.
            $table->unsignedInteger('account_id')->primary();

            // A stable public identifier. `accounts.id` is an auto-increment and
            // leaks how many accounts exist, which is why it never appears in a
            // v2 URL or payload.
            $table->uuid('uuid')->unique();

            // The first and last time this account signed in through the 2.0 API.
            $table->timestamp('first_v2_login_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            /*
             | Set the first time this account signs in through a properly
             | authenticated client — the Android/Flutter path, which carries a
             | bearer token and an install-keyed signature.
             |
             | After that the **Apple** path, whose entire authentication is a
             | static secret printed inside every App Store binary and in a
             | `test.html` served over the web, refuses to answer for this
             | account. That is what makes accepting that secret survivable: the
             | exposure shrinks with every person who upgrades, instead of
             | lasting as long as the database does.
             */
            $table->timestamp('v1_sealed_at')->nullable()->index();

            // Agreement to keep a copy of their writing on the server. Backup is
            // free (D-001), but it is still theirs to switch off.
            $table->timestamp('sync_consent_at')->nullable();
            $table->timestamp('sync_consent_withdrawn_at')->nullable();

            // Set when the account asks to be deleted; the erase runs on a queue.
            $table->timestamp('deletion_requested_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_security');
    }
};
