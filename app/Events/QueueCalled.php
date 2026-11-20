<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class QueueCalled implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $departmentId,
        public ?string $departmentCode,
        public int $stationId,
        public ?string $stationCode,
        public string $queueNumber,
        public string $status,
        public ?string $calledAt,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('public.queue'),
            new Channel("department.{$this->departmentId}"),
        ];
    }

    /**
     * The event name broadcasts as.
     */
    public function broadcastAs(): string
    {
        return 'queue.called';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'department_id' => $this->departmentId,
            'department_code' => $this->departmentCode,
            'station_id' => $this->stationId,
            'station_code' => $this->stationCode,
            'queue_number' => $this->queueNumber,
            'status' => $this->status,
            'called_at' => $this->calledAt,
        ];
    }
}
