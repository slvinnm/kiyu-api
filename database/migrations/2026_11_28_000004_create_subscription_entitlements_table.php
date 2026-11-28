<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the entitlements a subscription was granted at activation.
 *
 * The catalogue (`plan_entitlements`, `addon_entitlements`) is config for the
 * next purchase; the engine reads this snapshot plus active add-on snapshots, so
 * editing the catalogue never changes what an existing subscriber bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('feature_code');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'feature_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_entitlements');
    }
};
