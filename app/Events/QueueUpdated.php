<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Generic "queue changed" signal. Displays and staff workspaces listen to the
 * relevant channel and refetch their data. Carries no PII — only identifiers
 * and the reason for the change.
 */
class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public ?string $eventId;

    public function __construct(
        public ?int $departmentId = null,
        public ?int $stationId = null,
        public string $reason = 'updated',
    ) {
        $this->eventId = (string) Str::uuid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new Channel('public.queue')];

        if ($this->departmentId !== null) {
            $channels[] = new Channel("department.{$this->departmentId}");
        }

        if ($this->stationId !== null) {
            $channels[] = new Channel("station.{$this->stationId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'department_id' => $this->departmentId,
            'station_id' => $this->stationId,
            'reason' => $this->reason,
            'at' => now()->toIso8601String(),
        ];
    }
}
