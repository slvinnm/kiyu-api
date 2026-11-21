<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The audit log list sorts by `created_at` and filters by `user_id`, but
     * neither had a supporting index. The checks keep this migration safe for
     * databases where the index was added by hand before it was committed.
     */
    public function up(): void
    {
        $existing = collect(Schema::getIndexes('audit_logs'))->pluck('name');

        Schema::table('audit_logs', function (Blueprint $table) use ($existing): void {
            if (! $existing->contains('audit_logs_created_at_index')) {
                $table->index('created_at');
            }

            if (! $existing->contains('audit_logs_user_id_index')) {
                $table->index('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $existing = collect(Schema::getIndexes('audit_logs'))->pluck('name');

        Schema::table('audit_logs', function (Blueprint $table) use ($existing): void {
            if ($existing->contains('audit_logs_created_at_index')) {
                $table->dropIndex(['created_at']);
            }

            if ($existing->contains('audit_logs_user_id_index')) {
                $table->dropIndex(['user_id']);
            }
        });
    }
};
