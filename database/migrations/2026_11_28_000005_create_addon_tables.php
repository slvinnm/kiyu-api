<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-on catalogue, eligibility, subscription-bound purchases and usage ledger.
 *
 * An add-on is always attached to a subscription (never to a bare tenant) and
 * follows that subscription's billing lifecycle. Purchased grants are
 * snapshotted onto the subscription add-on so catalogue edits never change what
 * was already bought. Quota consumption is recorded in `addon_usage_events`,
 * keyed idempotently so a retried job or webhook cannot double-consume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('addon_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->string('billing_interval'); // MONTHLY, YEARLY
            $table->integer('amount');
            $table->string('currency', 3);
            $table->string('provider')->default('');
            $table->string('provider_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['addon_id', 'billing_interval', 'currency', 'provider', 'is_active'], 'addon_prices_lookup_index');
        });

        Schema::create('addon_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->string('feature_code');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['addon_id', 'feature_code']);
        });

        Schema::create('plan_addon_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'addon_id']);
        });

        Schema::create('subscription_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->foreignId('addon_id')->constrained('addons')->restrictOnDelete();
            $table->foreignId('addon_price_id')->nullable()->constrained('addon_prices')->nullOnDelete();
            $table->string('status'); // ACTIVE, CANCELLED, EXPIRED
            $table->integer('quantity')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });

        Schema::create('subscription_addon_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_addon_id')->constrained('subscription_addons')->restrictOnDelete();
            $table->string('feature_code');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['subscription_addon_id', 'feature_code'], 'sub_addon_entitlements_unique');
        });

        Schema::create('addon_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_addon_id')->constrained('subscription_addons')->restrictOnDelete();
            $table->string('feature_code');
            $table->integer('quantity')->default(1);
            $table->string('idempotency_key')->unique();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_usage_events');
        Schema::dropIfExists('subscription_addon_entitlements');
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('plan_addon_eligibilities');
        Schema::dropIfExists('addon_entitlements');
        Schema::dropIfExists('addon_prices');
        Schema::dropIfExists('addons');
    }
};
