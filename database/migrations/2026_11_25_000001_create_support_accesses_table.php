<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform support access (PRD §10.7, §19).
 *
 * An explicit, time-boxed, reasoned grant for a platform operator to act inside
 * one tenant. It is a record of consent, never a silent impersonation: the
 * tenant workspace keeps enforcing its own authorization and plan entitlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('platform_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'ended_at']);
            $table->index(['platform_user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_accesses');
    }
};
