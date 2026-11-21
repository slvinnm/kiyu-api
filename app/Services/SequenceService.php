<?php

namespace App\Services;

use App\Models\QueueCounter;
use App\Models\Station;
use App\Models\VisitCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SequenceService
{
    /**
     * Generate the next unique visit number for today.
     */
    public function generateVisitNumber(): string
    {
        $today = Carbon::today()->format('Y-m-d');

        return DB::transaction(function () use ($today): string {
            $counter = $this->lockCounter(VisitCounter::class, ['counter_date' => $today]);

            $counter->last_sequence += 1;
            $counter->save();

            return sprintf('V-%s-%04d', Carbon::today()->format('Ymd'), $counter->last_sequence);
        });
    }

    /**
     * Generate the next queue number and internal sequence for a station today.
     *
     * @return array{queue_number: string, internal_sequence: int}
     */
    public function generateQueueNumber(Station $station): array
    {
        $today = Carbon::today()->format('Y-m-d');

        return DB::transaction(function () use ($station, $today): array {
            $counter = $this->lockCounter(QueueCounter::class, [
                'station_id' => $station->id,
                'counter_date' => $today,
            ]);

            $counter->last_sequence += 1;
            $counter->save();

            $seq = $counter->last_sequence;
            $prefix = $station->queue_prefix ?: 'A';
            $padded = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

            return [
                'queue_number' => $prefix . $padded,
                'internal_sequence' => $seq,
            ];
        });
    }

    /**
     * Locks the counter row for today, creating it first when the day is new.
     *
     * Creating and locking in one step is not enough under concurrency: two
     * requests can both see no row and both insert, so one hits the unique
     * index. `insertOrIgnore` makes the create idempotent, and the follow-up
     * `lockForUpdate` serialises both requests on the row that now exists.
     *
     * @param  class-string<QueueCounter|VisitCounter>  $model
     * @param  array<string, mixed>  $keys
     */
    private function lockCounter(string $model, array $keys): QueueCounter|VisitCounter
    {
        $model::query()->insertOrIgnore($keys + ['last_sequence' => 0]);

        /** @var QueueCounter|VisitCounter */
        return $model::query()
            ->where($keys)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
