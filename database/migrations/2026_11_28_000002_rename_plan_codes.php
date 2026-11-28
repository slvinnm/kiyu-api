<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the legacy plan codes in place, preserving primary keys.
 *
 * STANDARD -> ESSENTIAL and MULTI_FACILITY -> GROWTH keep their row id, so every
 * subscription, payment and historical reference keeps pointing at the same
 * plan. If a target code already exists the migration aborts with a clear
 * message rather than duplicating plans; that conflict must be merged by hand.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $renames = [
        'STANDARD' => 'ESSENTIAL',
        'MULTI_FACILITY' => 'GROWTH',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            $source = DB::table('plans')->where('code', $from)->first();

            if ($source === null) {
                continue;
            }

            $collision = DB::table('plans')->where('code', $to)->exists();

            if ($collision) {
                throw new RuntimeException(
                    "Cannot rename plan '{$from}' to '{$to}': a plan with code '{$to}' already exists. " .
                    'Merge the two plans manually before running this migration.',
                );
            }

            DB::table('plans')->where('id', $source->id)->update([
                'code' => $to,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            $collision = DB::table('plans')->where('code', $from)->exists();

            if ($collision) {
                throw new RuntimeException(
                    "Cannot revert '{$to}' to '{$from}': a plan with code '{$from}' already exists.",
                );
            }

            DB::table('plans')->where('code', $to)->update([
                'code' => $from,
                'updated_at' => now(),
            ]);
        }
    }
};
