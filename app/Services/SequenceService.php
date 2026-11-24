<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Facility;
use App\Models\QueueCounter;
use App\Models\QueueSessionCounter;
use App\Models\Station;
use App\Support\LocalDay;
use Illuminate\Support\Facades\DB;

class SequenceService
{
    /**
     * Generate the next visit number for a facility today.
     *
     * The counter is keyed by facility and date, so two facilities may emit the
     * same formatted number without colliding. The counter row is never reset;
     * `last_sequence` continues from whatever already exists. "Today" is the
     * facility's local day, not the app timezone's.
     */
    public function generateSessionNumber(Facility $facility): string
    {
        $timezone = $facility->timezone ?: $facility->tenant?->timezone;
        $today = LocalDay::date($timezone);

        return DB::transaction(function () use ($facility, $today, $timezone): string {
            $counter = $this->lockCounter(QueueSessionCounter::class, [
                'facility_id' => $facility->id,
                'counter_date' => $today,
            ]);

            $counter->last_sequence += 1;
            $counter->save();

            return sprintf('V-%s-%04d', LocalDay::compactDate($timezone), $counter->last_sequence);
        });
    }

    /**
     * Generate the next queue number and internal sequence for a station today.
     *
     * The station is expected to have been authorised for the current tenant
     * and facility before this is called.
     *
     * @return array{queue_number: string, internal_sequence: int}
     */
    public function generateQueueNumber(Station $station): array
    {
        $timezone = $station->department?->facility?->timezone
            ?: $station->department?->facility?->tenant?->timezone;
        $today = LocalDay::date($timezone);

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
     * @param  class-string<QueueCounter|QueueSessionCounter>  $model
     * @param  array<string, mixed>  $keys
     */
    private function lockCounter(string $model, array $keys): QueueCounter|QueueSessionCounter
    {
        if (! array_key_exists('counter_date', $keys)) {
            throw new InvalidStateException('A counter key must include counter_date.');
        }

        $model::query()->insertOrIgnore($keys + ['last_sequence' => 0]);

        /** @var QueueCounter|QueueSessionCounter */
        return $model::query()
            ->where($keys)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
