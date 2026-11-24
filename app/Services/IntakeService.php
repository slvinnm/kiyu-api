<?php

namespace App\Services;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Exceptions\BusinessConflictException;
use App\Exceptions\InvalidStateException;
use App\Models\Client;
use App\Models\Department;
use App\Models\Facility;
use App\Models\QueueAcquisition;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Tenant;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntakeService
{
    public function __construct(
        public SequenceService $sequenceService,
        public WorkflowService $workflowService,
        public AuditService $auditService
    ) {}

    /**
     * Create a Kiosk walk-in intake.
     *
     * @return array{visit: Visit, acquisition: QueueAcquisition, ticket: QueueTicket}
     */
    public function createKioskIntake(
        Tenant $tenant,
        int $departmentId,
        int $priority = 1,
        ?string $idempotencyKey = null
    ): array {
        return $this->processPhysicalIntake(
            tenant: $tenant,
            departmentId: $departmentId,
            channel: IntakeChannel::Kiosk,
            priority: Priority::tryFrom($priority) ?? Priority::Normal,
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * Create a Manual walk-in intake by reception.
     *
     * @return array{visit: Visit, acquisition: QueueAcquisition, ticket: QueueTicket}
     */
    public function createManualIntake(
        Tenant $tenant,
        int $departmentId,
        int $priority = 1,
        ?string $idempotencyKey = null,
        ?string $registeredBy = null
    ): array {
        return $this->processPhysicalIntake(
            tenant: $tenant,
            departmentId: $departmentId,
            channel: IntakeChannel::Manual,
            priority: Priority::tryFrom($priority) ?? Priority::Normal,
            idempotencyKey: $idempotencyKey,
            registeredBy: $registeredBy
        );
    }

    /**
     * Create an Online booking intake for an existing client.
     *
     * @return array{visit: Visit, acquisition: QueueAcquisition, ticket: null}
     */
    public function createOnlineBooking(
        Tenant $tenant,
        int $clientId,
        int $departmentId,
        int $priority = 1,
        ?string $idempotencyKey = null
    ): array {
        $facility = $this->resolveFacility($tenant);

        /** @var Client $client */
        $client = Client::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($clientId);

        $department = $this->departmentWithin($tenant, $departmentId);
        $workflow = $this->workflowService->resolvePrimaryWorkflow($department->id);

        if ($idempotencyKey) {
            $existingAcq = $this->findAcquisitionForTenant($tenant, $idempotencyKey);

            if ($existingAcq) {
                if ($existingAcq->channel === IntakeChannel::Online && (int) $existingAcq->department_id === $departmentId) {
                    return [
                        'visit' => $existingAcq->visit->load(['client', 'department', 'workflow']),
                        'acquisition' => $existingAcq,
                        'ticket' => null,
                    ];
                }
                throw new BusinessConflictException("Idempotency key '{$idempotencyKey}' conflict with different parameters.");
            }
        }

        return DB::transaction(function () use ($tenant, $facility, $client, $department, $workflow, $priority, $idempotencyKey): array {
            $visitNumber = $this->sequenceService->generateVisitNumber($facility);
            $visitToken = (string) Str::uuid();
            $onlineActiveKey = (string) Str::uuid();

            /** @var Visit $visit */
            $visit = Visit::query()->create([
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'client_id' => $client->id,
                'department_id' => $department->id,
                'workflow_id' => $workflow->id,
                'visit_number' => $visitNumber,
                'visit_token' => $visitToken,
                'priority' => Priority::tryFrom($priority) ?? Priority::Normal,
                'intake_channel' => IntakeChannel::Online,
                'status' => VisitStatus::AwaitingCheckin,
                'online_active_key' => $onlineActiveKey,
            ]);

            /** @var QueueAcquisition $acquisition */
            $acquisition = QueueAcquisition::query()->create([
                'visit_id' => $visit->id,
                'department_id' => $department->id,
                'channel' => IntakeChannel::Online,
                'status' => QueueAcquisitionStatus::Acquired,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->auditService->log(
                tenant: $tenant,
                action: 'ONLINE_BOOKING_CREATED',
                entityType: 'Visit',
                entityId: $visit->id,
                metadata: [
                    'visit_number' => $visitNumber,
                    'client_id' => $client->id,
                    'department_id' => $department->id,
                ]
            );

            return [
                'visit' => $visit->load(['client', 'department', 'workflow']),
                'acquisition' => $acquisition,
                'ticket' => null,
            ];
        });
    }

    /**
     * Preview / lookup online booking without checking in (for kiosk screen).
     *
     * @return array{visit: Visit, client_preview: array{name: string, mrn: string}, department: string, is_eligible_checkin: bool, status: string}
     */
    public function lookupOnlineBooking(Tenant $tenant, string $bookingIdentifier): array
    {
        /** @var Visit|null $visit */
        $visit = $this->bookingQuery($tenant, $bookingIdentifier)
            ->with(['client', 'department', 'workflow'])
            ->first();

        if (! $visit) {
            throw new InvalidStateException("Visit not found for booking identifier '{$bookingIdentifier}'.");
        }

        // Mask client name for kiosk privacy (e.g., "Budi Santoso" -> "Bu** Sa*****")
        $name = $visit->client?->name ?? 'Unknown';
        $words = explode(' ', $name);
        $maskedWords = array_map(function ($word) {
            $len = strlen($word);
            if ($len <= 2) {
                return $word;
            }

            return substr($word, 0, 2) . str_repeat('*', $len - 2);
        }, $words);
        $maskedName = implode(' ', $maskedWords);

        // A bare identifier is enough to preview a booking, so the preview exposes
        // only what the patient needs to recognise it. The check-in credential
        // (online_active_key) is deliberately not echoed: echoing it would let a
        // holder of one identifier perform the check-in without seeing the screen.
        return [
            'visit_number' => $visit->visit_number,
            'client_preview' => [
                'masked_name' => $maskedName,
            ],
            'department_name' => $visit->department?->name ?? '-',
            'status' => $visit->status->value,
            'is_eligible_checkin' => $visit->status === VisitStatus::AwaitingCheckin,
        ];
    }

    /**
     * Perform online check-in at kiosk to enter reception queue.
     *
     * @return array{visit: Visit, ticket: QueueTicket}
     */
    public function performOnlineCheckin(Tenant $tenant, string $bookingIdentifier): array
    {
        return DB::transaction(function () use ($tenant, $bookingIdentifier): array {
            /** @var Visit|null $visit */
            $visit = $this->bookingQuery($tenant, $bookingIdentifier)
                ->lockForUpdate()
                ->first();

            if (! $visit) {
                throw new InvalidStateException("Visit not found for booking identifier '{$bookingIdentifier}'.");
            }

            if ($visit->status !== VisitStatus::AwaitingCheckin) {
                throw new InvalidStateException("Visit #{$visit->visit_number} is in status '{$visit->status->value}', cannot check in.");
            }

            // Idempotency: prevent double check-in for the same visit
            $existingTicket = QueueTicket::query()
                ->where('visit_id', $visit->id)
                ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called, QueueTicketStatus::InProgress, QueueTicketStatus::OnHold])
                ->first();

            if ($existingTicket) {
                throw new InvalidStateException("Visit #{$visit->visit_number} has already checked in and holds an active queue ticket (#{$existingTicket->queue_number}).");
            }

            $workflow = $visit->workflow;
            $registrationStep = $this->workflowService->resolveRegistrationStep($workflow);
            $registrationStation = $registrationStep->station;

            $numbering = $this->sequenceService->generateQueueNumber($registrationStation);

            $visit->update([
                'status' => VisitStatus::Waiting,
                'checked_in_at' => now(),
            ]);

            /** @var QueueTicket $ticket */
            $ticket = QueueTicket::query()->create([
                'visit_id' => $visit->id,
                'workflow_step_id' => $registrationStep->id,
                'station_id' => $registrationStation->id,
                'queue_number' => $numbering['queue_number'],
                'priority' => $visit->priority,
                'internal_sequence' => $numbering['internal_sequence'],
                'status' => QueueTicketStatus::Created,
            ]);

            QueueEvent::query()->create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => QueueEventType::Created,
                'from_status' => null,
                'to_status' => QueueTicketStatus::Created->value,
                'payload' => ['reason' => 'Online self check-in verified at kiosk'],
            ]);

            $this->auditService->log(
                tenant: $tenant,
                action: 'ONLINE_CHECKIN_COMPLETED',
                entityType: 'Visit',
                entityId: $visit->id,
                metadata: [
                    'queue_number' => $ticket->queue_number,
                    'station_id' => $registrationStation->id,
                ]
            );

            return [
                'visit' => $visit->fresh(['client', 'department', 'workflow']),
                'ticket' => $ticket->load(['station', 'workflowStep']),
            ];
        });
    }

    /**
     * Shared logic for physical walk-in (Kiosk or Manual).
     *
     * @return array{visit: Visit, acquisition: QueueAcquisition, ticket: QueueTicket}
     */
    protected function processPhysicalIntake(
        Tenant $tenant,
        int $departmentId,
        IntakeChannel $channel,
        Priority $priority,
        ?string $idempotencyKey = null,
        ?string $registeredBy = null
    ): array {
        $facility = $this->resolveFacility($tenant);
        $department = $this->departmentWithin($tenant, $departmentId);

        if ($idempotencyKey) {
            $existingAcq = $this->findAcquisitionForTenant($tenant, $idempotencyKey);

            if ($existingAcq) {
                if ($existingAcq->channel === $channel && (int) $existingAcq->department_id === $departmentId) {
                    $visit = $existingAcq->visit->load(['client', 'department', 'workflow']);
                    $ticket = $visit->activeQueueTicket ?? $visit->queueTickets()->latest('id')->first();

                    return [
                        'visit' => $visit,
                        'acquisition' => $existingAcq,
                        'ticket' => $ticket,
                    ];
                }
                throw new BusinessConflictException("Idempotency key '{$idempotencyKey}' conflict with different parameters.");
            }
        }

        $workflow = $this->workflowService->resolvePrimaryWorkflow($department->id);
        $registrationStep = $this->workflowService->resolveRegistrationStep($workflow);
        $registrationStation = $registrationStep->station;

        return DB::transaction(function () use (
            $tenant,
            $facility,
            $department,
            $channel,
            $priority,
            $idempotencyKey,
            $registeredBy,
            $workflow,
            $registrationStep,
            $registrationStation
        ): array {
            $visitNumber = $this->sequenceService->generateVisitNumber($facility);
            $visitToken = (string) Str::uuid();
            $numbering = $this->sequenceService->generateQueueNumber($registrationStation);

            /** @var Visit $visit */
            $visit = Visit::query()->create([
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'client_id' => null,
                'department_id' => $department->id,
                'workflow_id' => $workflow->id,
                'visit_number' => $visitNumber,
                'visit_token' => $visitToken,
                'priority' => $priority,
                'intake_channel' => $channel,
                'status' => VisitStatus::Waiting,
                'registered_by' => $registeredBy,
                'checked_in_at' => now(),
            ]);

            /** @var QueueAcquisition $acquisition */
            $acquisition = QueueAcquisition::query()->create([
                'visit_id' => $visit->id,
                'department_id' => $department->id,
                'channel' => $channel,
                'status' => QueueAcquisitionStatus::Acquired,
                'idempotency_key' => $idempotencyKey,
                'registered_by' => $registeredBy,
            ]);

            /** @var QueueTicket $ticket */
            $ticket = QueueTicket::query()->create([
                'visit_id' => $visit->id,
                'workflow_step_id' => $registrationStep->id,
                'station_id' => $registrationStation->id,
                'queue_number' => $numbering['queue_number'],
                'priority' => $priority,
                'internal_sequence' => $numbering['internal_sequence'],
                'status' => QueueTicketStatus::Created,
            ]);

            QueueEvent::query()->create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => QueueEventType::Created,
                'from_status' => null,
                'to_status' => QueueTicketStatus::Created->value,
                'payload' => ['channel' => $channel->value],
            ]);

            $this->auditService->log(
                tenant: $tenant,
                action: 'PHYSICAL_INTAKE_CREATED',
                entityType: 'Visit',
                entityId: $visit->id,
                metadata: [
                    'channel' => $channel->value,
                    'visit_number' => $visitNumber,
                    'queue_number' => $ticket->queue_number,
                ]
            );

            return [
                'visit' => $visit->load(['client', 'department', 'workflow']),
                'acquisition' => $acquisition,
                'ticket' => $ticket->load(['station', 'workflowStep']),
            ];
        });
    }

    /**
     * The acquisition awaiting registration for a visit, if any.
     *
     * Registration completion needs exactly this record; the list endpoint is
     * for managing acquisitions and should not be used as a lookup by visit.
     */
    public function getActiveAcquisitionForVisit(Tenant $tenant, int $visitId): ?QueueAcquisition
    {
        return $this->acquisitionsWithin($tenant)
            ->with(['visit.client', 'department', 'visit.workflow'])
            ->where('visit_id', $visitId)
            ->where('status', QueueAcquisitionStatus::Acquired)
            ->latest('id')
            ->first();
    }

    /**
     * Get a specific queue acquisition with full relations.
     */
    public function getAcquisition(Tenant $tenant, int $id): QueueAcquisition
    {
        return $this->acquisitionsWithin($tenant)
            ->with([
                'visit.client',
                'department',
                'visit.workflow',
                'visit.queueTickets.station',
                'visit.queueTickets.workflowStep',
            ])
            ->findOrFail($id);
    }

    /**
     * Cancel a queue acquisition and its associated pending visit/tickets.
     */
    public function cancelAcquisition(
        Tenant $tenant,
        int $id,
        ?string $reason = null,
        ?string $userId = null
    ): QueueAcquisition {
        return DB::transaction(function () use ($tenant, $id, $reason, $userId): QueueAcquisition {
            /** @var QueueAcquisition $acquisition */
            $acquisition = $this->acquisitionsWithin($tenant)
                ->with('visit')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($acquisition->status === QueueAcquisitionStatus::Cancelled) {
                throw new InvalidStateException("Acquisition #{$id} is already cancelled.");
            }

            if ($acquisition->status === QueueAcquisitionStatus::Registered) {
                throw new BusinessConflictException("Cannot cancel acquisition #{$id} because registration has already completed. Cancel the visit instead.");
            }

            $acquisition->update([
                'status' => QueueAcquisitionStatus::Cancelled,
            ]);

            $visit = $acquisition->visit;
            if ($visit && in_array($visit->status, [VisitStatus::AwaitingCheckin, VisitStatus::Waiting], true)) {
                $visit->update([
                    'status' => VisitStatus::Cancelled,
                ]);

                // Cancel any pending registration tickets
                $activeTickets = QueueTicket::query()
                    ->where('visit_id', $visit->id)
                    ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called])
                    ->lockForUpdate()
                    ->get();

                foreach ($activeTickets as $ticket) {
                    $prevStatus = $ticket->status;
                    $ticket->update([
                        'status' => QueueTicketStatus::Cancelled,
                        'notes' => $reason ? "Acquisition cancelled: {$reason}" : 'Acquisition cancelled',
                    ]);

                    QueueEvent::query()->create([
                        'queue_ticket_id' => $ticket->id,
                        'event_type' => QueueEventType::Cancelled,
                        'from_status' => $prevStatus->value,
                        'to_status' => QueueTicketStatus::Cancelled->value,
                        'user_id' => $userId,
                        'payload' => ['reason' => $reason ?? 'Acquisition cancelled'],
                    ]);
                }
            }

            $this->auditService->log(
                tenant: $tenant,
                action: 'ACQUISITION_CANCELLED',
                entityType: 'QueueAcquisition',
                entityId: $acquisition->id,
                metadata: [
                    'reason' => $reason,
                    'visit_id' => $visit?->id,
                ],
                userId: $userId
            );

            return $acquisition->fresh(['visit.client', 'department']);
        });
    }

    /**
     * Resolve the tenant's single active facility for intake.
     */
    private function resolveFacility(Tenant $tenant): Facility
    {
        $facilities = $tenant->facilities()->where('is_active', true)->limit(2)->get();

        if ($facilities->count() === 1) {
            return $facilities->first();
        }

        if ($facilities->isEmpty()) {
            throw new InvalidStateException("Tenant '{$tenant->slug}' has no active facility.");
        }

        throw new InvalidStateException(
            "Tenant '{$tenant->slug}' has more than one active facility; a facility is required."
        );
    }

    /**
     * Resolve a department that belongs to the current tenant (and facility).
     */
    private function departmentWithin(Tenant $tenant, int $departmentId): Department
    {
        /** @var Department $department */
        $department = Department::query()
            ->whereHas('facility', fn($query) => $query->where('tenant_id', $tenant->id))
            ->whereKey($departmentId)
            ->firstOrFail();

        return $department;
    }

    /**
     * Scope an acquisition lookup to the current tenant through its visit.
     */
    private function acquisitionsWithin(Tenant $tenant)
    {
        return QueueAcquisition::query()
            ->whereHas('visit', fn($query) => $query->where('tenant_id', $tenant->id));
    }

    /**
     * Find an acquisition by idempotency key inside the current tenant only.
     */
    private function findAcquisitionForTenant(Tenant $tenant, string $idempotencyKey): ?QueueAcquisition
    {
        return $this->acquisitionsWithin($tenant)
            ->with('visit')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Build a tenant-scoped lookup for an online booking identifier.
     *
     * Only the booking's own key is a valid identifier. Falling back to the
     * sequential visit id or the formatted visit number would let a kiosk
     * enumerate every visit in the tenant.
     */
    private function bookingQuery(Tenant $tenant, string $bookingIdentifier)
    {
        return Visit::query()
            ->where('tenant_id', $tenant->id)
            ->where('online_active_key', $bookingIdentifier);
    }
}
