<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global feature registry and release switch.
 *
 * The backend Feature enum remains the code allowlist; this table stores the
 * operational metadata and the global `is_feature_released` switch. `false`
 * denies the feature in every tenant and context regardless of entitlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('value_type'); // BOOLEAN, CAPACITY, QUOTA
            $table->boolean('is_feature_released')->default(true);
            $table->text('release_note')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
