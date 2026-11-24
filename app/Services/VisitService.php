<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidStateException;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Visit;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VisitService
{
    public function __construct(
        public AuditService $auditService
    ) {}

    /**
     * Get the client-facing tracking information for a visit.
     */
    public function getTrackingData(string $token): array
    {
        $visit = Visit::query()
            ->with([
                'client:id,name',
                'department:id,code,name',
                'workflow.steps.station:id,name,code',
                'queueAcquisitions.department:id,code,name',
                'queueTickets' => fn($query) => $query->orderBy('created_at')->orderBy('id'),
                'queueTickets.station:id,name,code',
                'queueTickets.workflowStep:id,name,sequence',
                'queueTickets.transferredToStation:id,name,code',
                'queueTickets.events',
                'outgoingReferrals.targetDepartment:id,code,name',
                'outgoingReferrals.targetVisit:id,visit_number,status',
            ])
            ->where('visit_token', $token)
            ->first();

        if (! $visit) {
            throw new InvalidStateException("Visit not found for token '{$token}'.");
        }

        $currentTicket = $visit->queueTickets
            ->filter(fn($ticket) => $ticket->status->isActive())
            ->sortByDesc('id')
            ->first();

        $journey = $this->buildTrackingJourney($visit, $currentTicket);
        $timeline = $this->buildTrackingTimeline($visit);
        $acquisitions = $this->buildTrackingAcquisitions($visit);
        $referrals = $this->buildTrackingReferrals($visit);

        $totalSteps = $journey->count();

        $completedSteps = $journey
            ->where('status', 'COMPLETED')
            ->count();

        $progress = $totalSteps > 0
            ? (int) round(($completedSteps / $totalSteps) * 100)
            : 0;

        $visitStatus = $this->enumValue($visit->status);

        return [
            'visit' => [
                'visit_number' => $visit->visit_number,
                'status' => $visitStatus,
                'priority' => $this->enumValue($visit->priority),
                'intake_channel' => $this->enumValue($visit->intake_channel),

                'timestamps' => [
                    'created_at' => $this->formatTrackingDate($visit->created_at),
                    'checked_in_at' => $this->formatTrackingDate($visit->checked_in_at),
                    'registered_at' => $this->formatTrackingDate($visit->registered_at),
                    'completed_at' => $this->formatTrackingDate($visit->completed_at),
                ],
            ],

            'client' => [
                'name' => $visit->client?->name,
            ],

            'department' => [
                'name' => $visit->department?->name,
                'code' => $visit->department?->code,
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
            'referrals' => $referrals,

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
    private function buildTrackingJourney(Visit $visit, ?QueueTicket $currentTicket): Collection
    {
        return $visit->workflow?->steps
            ->map(function ($step) use ($visit, $currentTicket) {
                $tickets = $visit->queueTickets
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
    private function buildTrackingTimeline(Visit $visit): Collection
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

        return $visit->queueTickets
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
    private function buildTrackingAcquisitions(Visit $visit): Collection
    {
        return $visit->queueAcquisitions
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
     * Build outgoing referral information.
     */
    private function buildTrackingReferrals(Visit $visit): Collection
    {
        return $visit->outgoingReferrals
            ->map(fn($referral) => [
                'status' => $this->enumValue($referral->status),

                'target_department' => $referral->targetDepartment ? [
                    'name' => $referral->targetDepartment->name,
                    'code' => $referral->targetDepartment->code,
                ] : null,

                'target_visit' => $referral->targetVisit ? [
                    'visit_number' => $referral->targetVisit->visit_number,
                    'status' => $this->enumValue($referral->targetVisit->status),
                ] : null,

                'created_at' => $this->formatTrackingDate($referral->created_at),
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
    public function updatePriority(Visit $visit, int $priority, ?string $userId = null): Visit
    {
        $newPriority = Priority::tryFrom($priority);
        if (! $newPriority) {
            throw new InvalidStateException("Invalid priority value: {$priority}. Must be 1 (Normal), 2 (Priority), or 3 (Emergency).");
        }

        return DB::transaction(function () use ($visit, $newPriority, $userId): Visit {
            $prevPriority = $visit->priority;
            $visit->update(['priority' => $newPriority]);

            // Propagate ONLY to active / pending tickets
            $activeTickets = QueueTicket::query()
                ->where('visit_id', $visit->id)
                ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called, QueueTicketStatus::OnHold])
                ->lockForUpdate()
                ->get();

            foreach ($activeTickets as $ticket) {
                $ticket->update(['priority' => $newPriority]);
            }

            $this->auditService->log(
                action: 'VISIT_PRIORITY_CHANGED',
                entityType: 'Visit',
                entityId: $visit->id,
                metadata: [
                    'from_priority' => $prevPriority->value,
                    'to_priority' => $newPriority->value,
                    'updated_ticket_count' => $activeTickets->count(),
                ],
                userId: $userId,
                tenant: $visit->tenant_id,
            );

            return $visit->fresh(['client', 'department', 'workflow', 'queueTickets']);
        });
    }

    /**
     * Cancel a visit and all its active tickets.
     */
    public function cancelVisit(Visit $visit, ?string $reason = null, ?string $userId = null): Visit
    {
        return DB::transaction(function () use ($visit, $reason, $userId): Visit {
            if ($visit->status === VisitStatus::Cancelled) {
                throw new InvalidStateException("Visit #{$visit->visit_number} is already cancelled.");
            }

            if ($visit->status === VisitStatus::Completed) {
                throw new InvalidStateException("Completed visit #{$visit->visit_number} cannot be cancelled.");
            }

            $visit->update(['status' => VisitStatus::Cancelled]);

            // Cancel any active tickets
            $activeTickets = QueueTicket::query()
                ->where('visit_id', $visit->id)
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
            if ($visit->queueAcquisition && $visit->queueAcquisition->status === QueueAcquisitionStatus::Acquired) {
                $visit->queueAcquisition->update(['status' => QueueAcquisitionStatus::Cancelled]);
            }

            $this->auditService->log(
                action: 'VISIT_CANCELLED',
                entityType: 'Visit',
                entityId: $visit->id,
                metadata: ['reason' => $reason],
                userId: $userId,
                tenant: $visit->tenant_id,
            );

            return $visit->fresh(['client', 'department', 'workflow', 'queueTickets']);
        });
    }
}
