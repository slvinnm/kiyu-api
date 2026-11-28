<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment line items with a price snapshot.
 *
 * Each item references exactly one price type (a plan price or an add-on price);
 * the amount, currency and name are snapshotted so later catalogue edits do not
 * rewrite a historical payment. A paid payment is never cascade-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->foreignId('addon_price_id')->nullable()->constrained('addon_prices')->nullOnDelete();
            $table->foreignId('subscription_addon_id')->nullable()->constrained('subscription_addons')->nullOnDelete();
            $table->string('name_snapshot');
            $table->integer('unit_amount');
            $table->integer('quantity')->default(1);
            $table->integer('total_amount');
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
    }
};
