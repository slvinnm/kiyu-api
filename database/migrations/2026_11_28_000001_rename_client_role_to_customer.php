<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the `client` role value to `customer`.
 *
 * The role was renamed to match the `customers` domain model. No row is added
 * or removed; only the stored string changes, so foreign keys, assignments and
 * audit history keep pointing at the same accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'client')->update(['role' => 'customer']);
        DB::table('tenant_invitations')->where('role', 'client')->update(['role' => 'customer']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'customer')->update(['role' => 'client']);
        DB::table('tenant_invitations')->where('role', 'customer')->update(['role' => 'client']);
    }
};
