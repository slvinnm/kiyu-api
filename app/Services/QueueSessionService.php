<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueSessionStatus;
use App\Enums\QueueTicketStatus;
use App\Exceptions\InvalidStateException;
use App\Models\QueueEvent;
use App\Models\QueueSession;
use App\Models\QueueTicket;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QueueSessionService
{
    public function __construct(
        public AuditService $auditService
    ) {}

    /**
     * Get the public tracking information for a queue session.
     */
    public function getTrackingData(string $token): array
    {
        $session = QueueSession::query()
            ->with([
                'customer:id,name',
                'department:id,code,name',
                'workflow.steps.station:id,name,code',
                'queueAcquisitions.department:id,code,name',
                'queueTickets' => fn($query) => $query->orderBy('created_at')->orderBy('id'),
                'queueTickets.station:id,name,code',
                'queueTickets.workflowStep:id,name,sequence',
                'queueTickets.transferredToStation:id,name,code',
                'queueTickets.events',
                'outgoingTransfers.targetDepartment:id,code,name',
                'outgoingTransfers.targetQueueSession:id,session_number,status',
            ])
            ->where('tracking_token', $token)
            ->first();

        if (! $session) {
            throw new InvalidStateException("QueueSession not found for token '{$token}'.");
        }

        $currentTicket = $session->queueTickets
            ->filter(fn($ticket) => $ticket->status->isActive())
            ->sortByDesc('id')
            ->first();

        $journey = $this->buildTrackingJourney($session, $currentTicket);
        $timeline = $this->buildTrackingTimeline($session);
        $acquisitions = $this->buildTrackingAcquisitions($session);
        $service_transfers = $this->buildTrackingTransfers($session);

        $totalSteps = $journey->count();

        $completedSteps = $journey
            ->where('status', 'COMPLETED')
            ->count();

        $progress = $totalSteps > 0
            ? (int) round(($completedSteps / $totalSteps) * 100)
            : 0;

        $visitStatus = $this->enumValue($session->status);

        return [
            'queue_session' => [
                'session_number' => $session->session_number,
                'status' => $visitStatus,
                'priority' => $this->enumValue($session->priority),
                'intake_channel' => $this->enumValue($session->intake_channel),

                'timestamps' => [
                    'created_at' => $this->formatTrackingDate($session->created_at),
                    'checked_in_at' => $this->formatTrackingDate($session->checked_in_at),
                    'registered_at' => $this->formatTrackingDate($session->registered_at),
                    'completed_at' => $this->formatTrackingDate($session->completed_at),
                ],
            ],

            'customer' => [
                'name' => $session->customer?->name,
            ],

            'department' => [
                'name' => $session->department?->name,
                'code' => $session->department?->code,
            ],

            'current_queue' => $currentTicket ? [
                'queue_number' => $currentTicket->queue_number,
                'status' => $this->enumValue($currentTicket->status),

                'station' => $currentTicket->station ? [
                    'name' => $currentTicket->station->name,
                    'code' => $currentTicket->station->code,
                ] : null,

                'called_at' => $this->formatTrackingDate($currentTicket->called_at),
                'started_at' => $this->formatTrackingDate($currentTicket->started_at),
            ] : null,

            'journey' => $journey,
            'timeline' => $timeline,
            'acquisitions' => $acquisitions,
            'service_transfers' => $service_transfers,

            'summary' => [
                'status' => $visitStatus,
                'message' => $this->getTrackingStatusMessage($visitStatus),
                'completed_steps' => $completedSteps,
                'total_steps' => $totalSteps,
                'progress_percentage' => $progress,
            ],
        ];
    }

    /**
     * Build the workflow journey in its configured sequence.
     */
    private function buildTrackingJourney(QueueSession $session, ?QueueTicket $currentTicket): Collection
    {
        return $session->workflow?->steps
            ->map(function ($step) use ($session, $currentTicket) {
                $tickets = $session->queueTickets
                    ->where('workflow_step_id', $step->id)
                    ->sortBy('id')
                    ->values();

                $activeTicket = $tickets
                    ->filter(fn($ticket) => $ticket->status->isActive())
                    ->sortByDesc('id')
                    ->first();

                $latestTicket = $tickets->last();

                $status = $this->resolveTrackingStepStatus(
                    $tickets,
                    $activeTicket,
                    $latestTicket
                );

                return [
                    'step' => [
                        'id' => $step->id,
                        'name' => $step->name,
                        'sequence' => $step->sequence,

                        'station' => $step->station ? [
                            'name' => $step->station->name,
                            'code' => $step->station->code,
                        ] : null,

                        'is_optional' => $step->is_optional,
                        'can_skip' => $step->can_skip,
                    ],

                    'status' => $status,

                    'is_current' => $activeTicket !== null
                        && $currentTicket?->id === $activeTicket->id,

                    'tickets' => $tickets->map(fn($ticket) => [
                        'queue_number' => $ticket->queue_number,
                        'status' => $this->enumValue($ticket->status),

                        'station' => $ticket->station ? [
                            'name' => $ticket->station->name,
                            'code' => $ticket->station->code,
                        ] : null,

                        'transferred_to' => $ticket->transferredToStation ? [
                            'name' => $ticket->transferredToStation->name,
                            'code' => $ticket->transferredToStation->code,
                        ] : null,

                        'is_current' => $currentTicket?->id === $ticket->id,

                        'timestamps' => [
                            'created_at' => $this->formatTrackingDate($ticket->created_at),
                            'called_at' => $this->formatTrackingDate($ticket->called_at),
                            'started_at' => $this->formatTrackingDate($ticket->started_at),
                            'completed_at' => $this->formatTrackingDate($ticket->completed_at),
                        ],
                    ]),
                ];
            })
            ->values() ?? collect();
    }

    /**
     * Resolve a workflow step status from its actual queue tickets.
     */
    private function resolveTrackingStepStatus(
        Collection $tickets,
        ?QueueTicket $activeTicket,
        ?QueueTicket $latestTicket
    ): string {
        if ($activeTicket) {
            return $this->enumValue($activeTicket->status);
        }

        if ($tickets->isEmpty()) {
            return 'NOT_STARTED';
        }

        // The latest ticket determines the current state of this step.
        // Earlier TRANSFERRED tickets must not prevent a replacement ticket
        // from making the step COMPLETED.
        return $this->enumValue($latestTicket->status);
    }

    /**
     * Build a chronological timeline from queue ticket events.
     */
    private function buildTrackingTimeline(QueueSession $session): Collection
    {
        $eventMessages = [
            'CREATED' => 'Queue ticket created.',
            'CALLED' => 'Your queue number has been called.',
            'RECALLED' => 'Your queue number has been called again.',
            'STARTED' => 'Your service has started.',
            'COMPLETED' => 'Your service has been completed.',
            'HELD' => 'Your service is temporarily on hold.',
            'RESUMED' => 'Your service has resumed.',
            'SKIPPED' => 'This queue step was skipped.',
            'CANCELLED' => 'This queue ticket was cancelled.',
            'NO_SHOW' => 'You were not present when your queue number was called.',
            'TRANSFERRED' => 'Your queue has been transferred to another station.',
        ];

        return $session->queueTickets
            ->flatMap(function ($ticket) use ($eventMessages) {
                return $ticket->events->map(function ($event) use ($ticket, $eventMessages) {
                    $eventType = $this->enumValue($event->event_type);

                    return [
                        'type' => $eventType,
                        'message' => $eventMessages[$eventType] ?? 'Queue status updated.',

                        'queue_number' => $ticket->queue_number,

                        'station' => $ticket->station ? [
                            'name' => $ticket->station->name,
                            'code' => $ticket->station->code,
                        ] : null,

                        // Used internally for chronological sorting.
                        '_sort_timestamp' => $event->created_at?->getTimestamp() ?? 0,
                        'occurred_at' => $this->formatTrackingDate($event->created_at),
                    ];
                });
            })
            ->sortBy('_sort_timestamp')
            ->map(fn($event) => collect($event)->except('_sort_timestamp')->all())
            ->values();
    }

    /**
     * Build queue acquisition history.
     */
    private function buildTrackingAcquisitions(QueueSession $session): Collection
    {
        return $session->queueAcquisitions
            ->map(fn($acquisition) => [
                'channel' => $this->enumValue($acquisition->channel),
                'status' => $this->enumValue($acquisition->status),

                'department' => $acquisition->department ? [
                    'name' => $acquisition->department->name,
                    'code' => $acquisition->department->code,
                ] : null,

                'created_at' => $this->formatTrackingDate($acquisition->created_at),
                'updated_at' => $this->formatTrackingDate($acquisition->updated_at),
            ])
            ->values();
    }

    /**
     * Build outgoing service-transfer information.
     */
    private function buildTrackingTransfers(QueueSession $session): Collection
    {
        return $session->outgoingTransfers
            ->map(fn($transfer) => [
                'status' => $this->enumValue($transfer->status),

                'target_department' => $transfer->targetDepartment ? [
                    'name' => $transfer->targetDepartment->name,
                    'code' => $transfer->targetDepartment->code,
                ] : null,

                'target_session' => $transfer->targetQueueSession ? [
                    'session_number' => $transfer->targetQueueSession->session_number,
                    'status' => $this->enumValue($transfer->targetQueueSession->status),
                ] : null,

                'created_at' => $this->formatTrackingDate($transfer->created_at),
            ])
            ->values();
    }

    /**
     * Get a client-facing message for the visit status.
     */
    private function getTrackingStatusMessage(?string $status): string
    {
        return match ($status) {
            'AWAITING_CHECKIN' => 'Your visit is waiting for check-in.',
            'WAITING' => 'Your visit is registered and waiting for service.',
            'IN_PROGRESS' => 'Your visit is currently in progress.',
            'COMPLETED' => 'Your visit has been completed.',
            'CANCELLED' => 'Your visit has been cancelled.',
            default => 'Your visit status has been updated.',
        };
    }

    /**
     * Convert a backed enum or scalar value into a string.
     */
    private function enumValue($value): ?string
    {
        return $value instanceof BackedEnum
            ? (string) $value->value
            : ($value === null ? null : (string) $value);
    }

    /**
     * Format a date for the tracking response.
     */
    private function formatTrackingDate($date): ?string
    {
        return $date?->toIso8601String();
    }

    /**
     * Update visit priority and propagate to active tickets only.
     */
    public function updatePriority(QueueSession $session, int $priority, ?int $userId = null): QueueSession
    {
        $newPriority = Priority::tryFrom($priority);
        if (! $newPriority) {
            throw new InvalidStateException("Invalid priority value: {$priority}. Must be 1 (Normal), 2 (Priority), or 3 (Emergency).");
        }

        return DB::transaction(function () use ($session, $newPriority, $userId): QueueSession {
            $prevPriority = $session->priority;
            $session->update(['priority' => $newPriority]);

            // Propagate only to tickets that have not started service. An
            // IN_PROGRESS ticket keeps the priority it was called with: the
            // client is already in the room, so re-ranking it has no effect on
            // the queue and would only rewrite the record of how it was served.
            $activeTickets = QueueTicket::query()
                ->where('queue_session_id', $session->id)
                ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called, QueueTicketStatus::OnHold])
                ->lockForUpdate()
                ->get();

            foreach ($activeTickets as $ticket) {
                $ticket->update(['priority' => $newPriority]);
            }

            $this->auditService->log(
                action: 'QUEUE_SESSION_PRIORITY_CHANGED',
                entityType: 'QueueSession',
                entityId: $session->id,
                metadata: [
                    'from_priority' => $prevPriority->value,
                    'to_priority' => $newPriority->value,
                    'updated_ticket_count' => $activeTickets->count(),
                ],
                userId: $userId,
                tenant: $session->tenant_id,
            );

            return $session->fresh(['customer', 'department', 'workflow', 'queueTickets']);
        });
    }

    /**
     * Cancel a visit and all its active tickets.
     */
    public function cancelSession(QueueSession $session, ?string $reason = null, ?int $userId = null): QueueSession
    {
        return DB::transaction(function () use ($session, $reason, $userId): QueueSession {
            if ($session->status === QueueSessionStatus::Cancelled) {
                throw new InvalidStateException("QueueSession #{$session->session_number} is already cancelled.");
            }

            if ($session->status === QueueSessionStatus::Completed) {
                throw new InvalidStateException("Completed visit #{$session->session_number} cannot be cancelled.");
            }

            $session->update(['status' => QueueSessionStatus::Cancelled]);

            // Cancel any active tickets
            $activeTickets = QueueTicket::query()
                ->where('queue_session_id', $session->id)
                ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called, QueueTicketStatus::InProgress, QueueTicketStatus::OnHold])
                ->lockForUpdate()
                ->get();

            foreach ($activeTickets as $ticket) {
                $prevStatus = $ticket->status;
                $ticket->update([
                    'status' => QueueTicketStatus::Cancelled,
                    'notes' => $reason ? ($ticket->notes ? "{$ticket->notes}. Reason: {$reason}" : "Reason: {$reason}") : $ticket->notes,
                ]);

                QueueEvent::query()->create([
                    'queue_ticket_id' => $ticket->id,
                    'event_type' => QueueEventType::Cancelled,
                    'from_status' => $prevStatus->value,
                    'to_status' => QueueTicketStatus::Cancelled->value,
                    'user_id' => $userId,
                    'payload' => ['reason' => $reason],
                ]);
            }

            // Cancel acquisition if still ACQUIRED
            if ($session->queueAcquisition && $session->queueAcquisition->status === QueueAcquisitionStatus::Acquired) {
                $session->queueAcquisition->update(['status' => QueueAcquisitionStatus::Cancelled]);
            }

            $this->auditService->log(
                action: 'VISIT_CANCELLED',
                entityType: 'QueueSession',
                entityId: $session->id,
                metadata: ['reason' => $reason],
                userId: $userId,
                tenant: $session->tenant_id,
            );

            return $session->fresh(['customer', 'department', 'workflow', 'queueTickets']);
        });
    }
}
