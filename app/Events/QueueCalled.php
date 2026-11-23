<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class QueueCalled implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public ?int $tenantId,
        public ?int $facilityId,
        public int $departmentId,
        public ?string $departmentCode,
        public int $stationId,
        public ?string $stationCode,
        public string $queueNumber,
        public string $status,
        public ?string $calledAt,
        public ?string $departmentName = null,
        public ?string $stationName = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * The public display is scoped per facility; staff workspaces use a
     * tenant-namespaced private channel.
     *
     * @return array<int, Channel|PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->facilityId !== null) {
            $channels[] = new Channel("public.facility.{$this->facilityId}");
        }

        if ($this->tenantId !== null) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.department.{$this->departmentId}");
        }

        return $channels;
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
            'department_name' => $this->departmentName,
            'station_id' => $this->stationId,
            'station_code' => $this->stationCode,
            'station_name' => $this->stationName,
            'queue_number' => $this->queueNumber,
            'status' => $this->status,
            'called_at' => $this->calledAt,
        ];
    }
}
