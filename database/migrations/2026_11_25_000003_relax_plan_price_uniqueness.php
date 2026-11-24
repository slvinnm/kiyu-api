<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan price is superseded, never mutated: a new row is inserted and the old
 * one is deactivated so historical payments keep their amount. That means two
 * rows may share (plan, interval, currency, provider) — one active, older ones
 * inactive — so the original four-column unique index is replaced by a normal
 * index. "One active price per key" is enforced by the service transaction.
 *
 * The composite unique index also backed the `plan_id` foreign key, so a plain
 * `plan_id` index is created first to keep the FK satisfied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->index('plan_id', 'plan_prices_plan_id_index');
        });

        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropUnique('plan_prices_plan_id_billing_interval_currency_provider_unique');
            $table->index(
                ['plan_id', 'billing_interval', 'currency', 'provider', 'is_active'],
                'plan_prices_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropIndex('plan_prices_lookup_index');
            $table->unique(['plan_id', 'billing_interval', 'currency', 'provider']);
        });

        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropIndex('plan_prices_plan_id_index');
        });
    }
};
