<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short, one-time pairing code for kiosk device onboarding.
 *
 * An admin registers a device and receives an 8-character code; the operator
 * types it on the kiosk. Redeeming the code issues the long-lived credential
 * and clears the code, so a code can only be used once. The code is stored as
 * a hash like the credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table): void {
            $table->string('pairing_code_hash', 64)->nullable()->unique()->after('credential_hash');
            $table->timestamp('pairing_expires_at')->nullable()->after('pairing_code_hash');
            // Set once the device has redeemed a code for a real credential.
            // Null means the device is registered but not yet operational, so
            // its credential must never be treated as live.
            $table->timestamp('paired_at')->nullable()->after('pairing_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table): void {
            $table->dropColumn(['pairing_code_hash', 'pairing_expires_at', 'paired_at']);
        });
    }
};
