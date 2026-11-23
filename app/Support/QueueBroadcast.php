<?php

namespace App\Support;

use App\Events\QueueCalled;
use App\Events\QueueUpdated;
use App\Models\QueueTicket;
use Illuminate\Support\Facades\Log;

/**
 * Central helper so every queue mutation emits the same refetch signal.
 *
 * Broadcasting must never break the business operation: if the Reverb server
 * is down, we log and continue.
 */
class QueueBroadcast
{
    public static function enabled(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        return config('broadcasting.default') !== 'null';
    }

    public static function updated(?int $tenantId, ?int $facilityId, ?int $departmentId, ?int $stationId, string $reason): void
    {
        if (! self::enabled() || ($departmentId === null && $stationId === null)) {
            return;
        }

        try {
            QueueUpdated::dispatch($tenantId, $facilityId, $departmentId, $stationId, $reason);
        } catch (\Throwable $e) {
            Log::warning('Queue update broadcast failed', [
                'department_id' => $departmentId,
                'station_id' => $stationId,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Announce that a specific ticket was called (drives the display popup).
     */
    public static function called(?QueueTicket $ticket): void
    {
        if (! $ticket || ! self::enabled()) {
            return;
        }

        try {
            $ticket->loadMissing(['station.department.facility']);

            QueueCalled::dispatch(
                tenantId: $ticket->station->department?->facility?->tenant_id,
                facilityId: $ticket->station->department?->facility_id,
                departmentId: $ticket->station->department_id,
                departmentCode: $ticket->station->department?->code,
                stationId: $ticket->station_id,
                stationCode: $ticket->station?->code,
                queueNumber: $ticket->queue_number,
                status: $ticket->status->value,
                calledAt: $ticket->called_at?->toIso8601String(),
                departmentName: $ticket->station->department?->name,
                stationName: $ticket->station?->name,
            );
        } catch (\Throwable $e) {
            Log::warning('Queue called broadcast failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
