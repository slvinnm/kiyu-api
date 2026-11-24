<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\QueueTicketStatus;
use App\Support\QueueBroadcast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class QueueTicket extends Model
{
    use HasFactory;

    /**
     * Emit a lightweight "queue changed" broadcast after the ticket is
     * committed. Staff workspaces and public displays listen to the station /
     * department channel and refetch, giving live updates for every flow
     * (call, start, complete, hold, transfer, new ticket, ...).
     */
    protected static function booted(): void
    {
        $emit = function (QueueTicket $ticket, string $reason): void {
            $stationId = $ticket->station_id;
            $session = $ticket->queueSession;
            $departmentId = $session?->department_id;
            $tenantId = $session?->tenant_id;
            $facilityId = $session?->facility_id;

            if ($departmentId === null && $stationId !== null) {
                $departmentId = Station::query()
                    ->whereKey($stationId)
                    ->value('department_id');
            }

            DB::afterCommit(function () use ($tenantId, $facilityId, $departmentId, $stationId, $reason): void {
                QueueBroadcast::updated($tenantId, $facilityId, $departmentId, $stationId, $reason);
            });
        };

        static::created(function (QueueTicket $ticket) use ($emit): void {
            $emit($ticket, 'created');
        });

        static::updated(function (QueueTicket $ticket) use ($emit): void {
            if ($ticket->wasChanged('status')) {
                $emit($ticket, strtolower($ticket->status->value));
            } elseif ($ticket->wasChanged('assigned_to_user_id')) {
                $emit($ticket, 'assigned');
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_session_id',
        'workflow_step_id',
        'station_id',
        'queue_number',
        'priority',
        'internal_sequence',
        'status',
        'assigned_to_user_id',
        'transferred_from_ticket_id',
        'transferred_to_station_id',
        'notes',
        'called_at',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'priority' => Priority::class,
        'internal_sequence' => 'integer',
        'status' => QueueTicketStatus::class,
        'called_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the queue session that owns this ticket.
     */
    public function queueSession(): BelongsTo
    {
        return $this->belongsTo(QueueSession::class);
    }

    /**
     * Get the workflow step for this ticket.
     */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    /**
     * Get the user currently assigned to serve this ticket.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Get the station serving this ticket.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the prior ticket if this ticket was transferred from another ticket.
     */
    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_ticket_id');
    }

    /**
     * Get the station this ticket was transferred to, if any.
     */
    public function transferredToStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'transferred_to_station_id');
    }

    /**
     * Get the events recorded for this ticket.
     */
    public function events(): HasMany
    {
        return $this->hasMany(QueueEvent::class)->orderBy('id');
    }
}
