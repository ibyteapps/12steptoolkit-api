<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The back office.
 *
 * A separate table and a separate guard from the app's accounts: nobody becomes
 * staff by signing in to the app. There is no registration page — accounts are
 * made with `php artisan console:user you@example.com`, which emails a
 * 30-minute set-password link.
 *
 * The old system's only back office was `19/admin/reported.php`, an
 * unauthenticated HTML page listing every user report with the reporter's id,
 * the reported person's id and the free-text reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 191)->unique();
            $table->string('password')->nullable(); // null until the set-password link is used
            $table->boolean('active')->default(true);
            $table->string('role', 24)->default('support'); // support | billing | admin
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('console_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        /*
         | Who did what. Every action that changes somebody's account, their
         | subscription or their ticket writes a row here, and the rows are kept
         | for two years.
         |
         | `subject_type`/`subject_id` name the thing, never its contents: the
         | console is built so that a staff member can help somebody without
         | reading a word of what they wrote, and the audit log must not be the
         | hole in that.
         */
        Schema::create('console_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('console_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 48)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Support threads. A ticket belongs to an install and, once somebody
        // signs in, to their account as well.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('install_id')->nullable()->index();
            $table->unsignedInteger('account_id')->nullable()->index();
            $table->string('email', 191)->nullable();
            $table->string('subject', 191);
            $table->string('category', 32)->default('other');
            $table->string('state', 16)->default('open'); // open | answered | closed
            $table->json('context')->nullable(); // app version, platform, locale — never content
            $table->timestamp('last_member_at')->nullable();
            $table->timestamp('last_staff_at')->nullable();
            $table->timestamp('member_read_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'last_member_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('console_user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_staff')->default(false);
            $table->text('body');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['support_messages', 'support_tickets', 'console_audit', 'console_password_reset_tokens', 'console_users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
