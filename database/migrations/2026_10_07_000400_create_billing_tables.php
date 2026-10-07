<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions, verified by this server, with RevenueCat read alongside.
 *
 * The legacy database already has `subscription_orders`, `sponsee_orders` and
 * `sponsee_order_users`, and `accounts.subscribed` as a flag. None of them is
 * touched: `accounts.subscribed` is still written by the legacy paths and is
 * still read by the old apps. What they cannot do is answer "is this person
 * premium *now*" — `accounts.subscribed` is never cleared once set, there is no
 * webhook anywhere in the old system, and so a cancellation, a refund or an
 * expiry is never learned.
 *
 * These tables answer it, and `EntitlementService` is the only thing that reads
 * them together.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The one row that answers "is this person premium?". Written by two
        // sources — store subscriptions verified here, and RevenueCat for the
        // old apps — and neither may take away access the other still grants.
        Schema::create('entitlements', function (Blueprint $table) {
            $table->unsignedInteger('account_id')->primary();
            $table->boolean('is_active')->default(false)->index();
            // active | trial | grace_period | cancelled | expired | none
            $table->string('state', 24)->default('none');
            $table->string('source', 24)->nullable(); // apple | google | revenuecat | complimentary | sponsee_gift
            $table->string('product_id', 191)->nullable();
            $table->string('cycle', 16)->nullable(); // weekly | monthly | quarterly | annual
            $table->boolean('will_renew')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('grace_period_expires_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            // RevenueCat's own answer, kept apart from the store-verified state so
            // that it can be read, audited and switched off without disturbing it.
            $table->json('revenuecat')->nullable();
            $table->timestamps();
        });

        // One row per store subscription. `account_id` is nullable because a
        // purchase can be made before anybody signs in — Apple and Google both
        // allow it, and the old system simply lost those.
        Schema::create('store_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('store', 8); // apple | google
            $table->unsignedInteger('account_id')->nullable()->index();
            $table->string('original_transaction_id', 191);
            $table->string('product_id', 191);
            $table->string('cycle', 16)->nullable();
            $table->string('status', 24)->default('active'); // active | grace_period | on_hold | paused | cancelled | expired | refunded
            $table->string('environment', 16)->default('production'); // production | sandbox
            $table->boolean('is_sandbox')->default(false)->index();
            $table->boolean('started_with_trial')->default(false);
            $table->boolean('will_renew')->default(true);
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('grace_period_expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('state_signed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            // Google only: the purchase token and its hash (the token is long and
            // is replaced on every upgrade/downgrade, so the chain is followed).
            $table->text('purchase_token')->nullable();
            $table->string('purchase_token_hash', 64)->nullable()->index();
            $table->string('linked_purchase_token_hash', 64)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['store', 'original_transaction_id']);
        });

        // One payment. Money is held in milli-units of the smallest currency unit
        // as integers, never floats: a float "£39.99" that has been through a
        // currency conversion and back is not £39.99 any more.
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store', 8);
            $table->unsignedInteger('account_id')->nullable()->index();
            $table->string('transaction_id', 191);
            $table->string('product_id', 191);
            $table->string('kind', 16)->default('purchase'); // purchase | renewal
            $table->string('phase', 16)->default('normal');  // normal | trial | intro | promotional
            $table->string('currency', 8)->nullable();
            $table->string('storefront', 8)->nullable();
            $table->bigInteger('amount_milli')->default(0);
            $table->bigInteger('tax_milli')->default(0);
            $table->bigInteger('revenue_milli')->default(0);
            $table->bigInteger('refunded_milli')->default(0);
            $table->bigInteger('gbp_milli')->default(0);
            $table->bigInteger('net_gbp_milli')->default(0);
            $table->double('gbp_rate')->nullable();
            $table->double('net_ratio')->nullable();
            $table->boolean('after_first_year')->default(false);
            $table->boolean('is_sandbox')->default(false)->index();
            $table->timestamp('purchased_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['store', 'transaction_id']);
        });

        // Raw provider payloads, kept for support and for idempotency. A webhook
        // that arrives twice must do its work once.
        Schema::create('apple_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notification_uuid', 64)->unique();
            $table->string('notification_type', 48)->nullable();
            $table->string('subtype', 48)->nullable();
            $table->string('original_transaction_id', 191)->nullable()->index();
            $table->string('environment', 16)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('google_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64)->unique();
            $table->string('notification_type', 48)->nullable();
            $table->string('purchase_token_hash', 64)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('revenuecat_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('type', 48)->nullable();
            $table->unsignedInteger('account_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Subscriptions given from the console: a refund gone wrong, a hardship,
        // a reviewer. They count as paid, run alongside store state, and stack.
        Schema::create('complimentary_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id')->index();
            $table->string('period', 16); // 1_month | 3_months | 1_year | lifetime
            $table->text('reason')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('console_users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable(); // null = lifetime
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'complimentary_grants', 'revenuecat_events', 'google_notifications',
            'apple_notifications', 'store_orders', 'store_subscriptions', 'entitlements',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
