<?php

namespace App\Services;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueSessionStatus;
use App\Enums\QueueTicketStatus;
use App\Exceptions\BusinessConflictException;
use App\Exceptions\InvalidStateException;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\QueueAcquisition;
use App\Models\QueueEvent;
use App\Models\QueueSession;
use App\Models\QueueTicket;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * @return array{queue_session: QueueSession, acquisition: QueueAcquisition, ticket: QueueTicket}
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
     * @return array{queue_session: QueueSession, acquisition: QueueAcquisition, ticket: QueueTicket}
     */
    public function createManualIntake(
        Tenant $tenant,
        int $departmentId,
        int $priority = 1,
        ?string $idempotencyKey = null,
        ?int $registeredByUserId = null
    ): array {
        return $this->processPhysicalIntake(
            tenant: $tenant,
            departmentId: $departmentId,
            channel: IntakeChannel::Manual,
            priority: Priority::tryFrom($priority) ?? Priority::Normal,
            idempotencyKey: $idempotencyKey,
            registeredByUserId: $registeredByUserId
        );
    }

    /**
     * Create an Online booking intake for an existing client.
     *
     * @return array{queue_session: QueueSession, acquisition: QueueAcquisition, ticket: null}
     */
    public function createOnlineBooking(
        Tenant $tenant,
        int $customerId,
        int $departmentId,
        int $priority = 1,
        ?string $idempotencyKey = null
    ): array {
        $facility = $this->resolveFacility($tenant);

        /** @var Customer $customer */
        $customer = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($customerId);

        $department = $this->departmentWithin($tenant, $departmentId);
        $workflow = $this->workflowService->resolvePrimaryWorkflow($department->id);

        if ($idempotencyKey) {
            $existingAcq = $this->findAcquisitionForTenant($tenant, $idempotencyKey);

            if ($existingAcq) {
                if ($existingAcq->channel === IntakeChannel::Online && (int) $existingAcq->department_id === $departmentId) {
                    // An online booking has no ticket until the client checks
                    // in, so `ticket` is null here by design. That is the same
                    // value the first, non-replayed call returned.
                    return [
                        'queue_session' => $existingAcq->queueSession->load(['customer', 'department', 'workflow']),
                        'acquisition' => $existingAcq,
                        'ticket' => null,
                    ];
                }
                throw new BusinessConflictException("Idempotency key '{$idempotencyKey}' conflict with different parameters.");
            }
        }

        try {
            return $this->createOnlineBookingWithinTransaction($tenant, $facility, $customer, $department, $workflow, $priority, $idempotencyKey);
        } catch (UniqueConstraintViolationException) {
            // The pre-check raced another identical request; the unique
            // (tenant_id, idempotency_key) index rejected the duplicate, so a
            // replay can return the winner's booking.
            $existingAcq = $idempotencyKey === null
                ? null
                : $this->findAcquisitionForTenant($tenant, $idempotencyKey);

            if ($existingAcq === null) {
                throw new BusinessConflictException('The booking could not be created because an identical one already exists.');
            }

            return [
                'queue_session' => $existingAcq->queueSession->load(['customer', 'department', 'workflow']),
                'acquisition' => $existingAcq,
                'ticket' => null,
            ];
        }
    }

    /**
     * The write half of {@see createOnlineBooking()}, inside one transaction.
     *
     * @return array{queue_session: QueueSession, acquisition: QueueAcquisition, ticket: null}
     */
    private function createOnlineBookingWithinTransaction(
        Tenant $tenant,
        Facility $facility,
        Customer $customer,
        Department $department,
        Workflow $workflow,
        int $priority,
        ?string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($tenant, $facility, $customer, $department, $workflow, $priority, $idempotencyKey): array {
            $sessionNumber = $this->sequenceService->generateSessionNumber($facility);
            $trackingToken = (string) Str::uuid();
            $onlineActiveKey = (string) Str::uuid();

            /** @var QueueSession $session */
            $session = QueueSession::query()->create([
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'customer_id' => $customer->id,
                'department_id' => $department->id,
                'workflow_id' => $workflow->id,
                'session_number' => $sessionNumber,
                'tracking_token' => $trackingToken,
                'priority' => Priority::tryFrom($priority) ?? Priority::Normal,
                'intake_channel' => IntakeChannel::Online,
                'status' => QueueSessionStatus::AwaitingCheckin,
                'online_active_key' => $onlineActiveKey,
            ]);

            /** @var QueueAcquisition $acquisition */
            $acquisition = QueueAcquisition::query()->create([
                'queue_session_id' => $session->id,
                'tenant_id' => $tenant->id,
                'department_id' => $department->id,
                'channel' => IntakeChannel::Online,
                'status' => QueueAcquisitionStatus::Acquired,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->auditService->log(
                tenant: $tenant,
                action: 'ONLINE_BOOKING_CREATED',
                entityType: 'QueueSession',
                entityId: $session->id,
                metadata: [
                    'session_number' => $sessionNumber,
                    'customer_id' => $customer->id,
                    'department_id' => $department->id,
                ]
            );

            return [
                'queue_session' => $session->load(['customer', 'department', 'workflow']),
                'acquisition' => $acquisition,
                'ticket' => null,
            ];
        });
    }

    /**
     * Preview / lookup online booking without checking in (for kiosk screen).
     *
     * @return array{queue_session: QueueSession, customer_preview: array{masked_name: string}, department: string, is_eligible_checkin: bool, status: string}
     */
    public function lookupOnlineBooking(Tenant $tenant, string $bookingIdentifier): array
    {
        /** @var QueueSession|null $session */
        $session = $this->bookingQuery($tenant, $bookingIdentifier)
            ->with(['customer', 'department', 'workflow'])
            ->first();

        if (! $session) {
            throw new InvalidStateException("QueueSession not found for booking identifier '{$bookingIdentifier}'.");
        }

        // Mask client name for kiosk privacy (e.g., "Budi Santoso" -> "Bu** Sa*****")
        $name = $session->customer?->name ?? 'Unknown';
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
        // only what the customer needs to recognise it. The check-in credential
        // (online_active_key) is deliberately not echoed: echoing it would let a
        // holder of one identifier perform the check-in without seeing the screen.
        return [
            'session_number' => $session->session_number,
            'customer_preview' => [
                'masked_name' => $maskedName,
            ],
            'department_name' => $session->department?->name ?? '-',
            'status' => $session->status->value,
            'is_eligible_checkin' => $session->status === QueueSessionStatus::AwaitingCheckin,
        ];
    }

    /**
     * Perform online check-in at kiosk to enter reception queue.
     *
     * @return array{queue_session: QueueSession, ticket: QueueTicket}
     */
    public function performOnlineCheckin(Tenant $tenant, string $bookingIdentifier): array
    {
        return DB::transaction(function () use ($tenant, $bookingIdentifier): array {
            /** @var QueueSession|null $session */
            $session = $this->bookingQuery($tenant, $bookingIdentifier)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new InvalidStateException("QueueSession not found for booking identifier '{$bookingIdentifier}'.");
            }

            if ($session->status !== QueueSessionStatus::AwaitingCheckin) {
                throw new InvalidStateException("QueueSession #{$session->session_number} is in status '{$session->status->value}', cannot check in.");
            }

            // Idempotency: prevent double check-in for the same visit
            $existingTicket = QueueTicket::query()
                ->where('queue_session_id', $session->id)
                ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called, QueueTicketStatus::InProgress, QueueTicketStatus::OnHold])
                ->first();

            if ($existingTicket) {
                throw new InvalidStateException("QueueSession #{$session->session_number} has already checked in and holds an active queue ticket (#{$existingTicket->queue_number}).");
            }

            $workflow = $session->workflow;
            $registrationStep = $this->workflowService->resolveRegistrationStep($workflow);
            $registrationStation = $registrationStep->station;

            $numbering = $this->sequenceService->generateQueueNumber($registrationStation);

            $session->update([
                'status' => QueueSessionStatus::Waiting,
                'checked_in_at' => now(),
            ]);

            /** @var QueueTicket $ticket */
            $ticket = QueueTicket::query()->create([
                'queue_session_id' => $session->id,
                'workflow_step_id' => $registrationStep->id,
                'station_id' => $registrationStation->id,
                'queue_number' => $numbering['queue_number'],
                'priority' => $session->priority,
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
                entityType: 'QueueSession',
                entityId: $session->id,
                metadata: [
                    'queue_number' => $ticket->queue_number,
                    'station_id' => $registrationStation->id,
                ]
            );

            return [
                'queue_session' => $session->fresh(['customer', 'department', 'workflow']),
                'ticket' => $ticket->load(['station', 'workflowStep']),
            ];
        });
    }

    /**
     * Shared logic for physical walk-in (Kiosk or Manual).
     *
     * @return array{queue_session: QueueSession, acquisition: QueueAcquisition, ticket: QueueTicket}
     */
    protected function processPhysicalIntake(
        Tenant $tenant,
        int $departmentId,
        IntakeChannel $channel,
        Priority $priority,
        ?string $idempotencyKey = null,
        ?int $registeredByUserId = null
    ): array {
        $facility = $this->resolveFacility($tenant);
        $department = $this->departmentWithin($tenant, $departmentId);

        if ($idempotencyKey) {
            $existingAcq = $this->findAcquisitionForTenant($tenant, $idempotencyKey);

            if ($existingAcq) {
                if ($existingAcq->channel === $channel && (int) $existingAcq->department_id === $departmentId) {
                    $session = $existingAcq->queueSession->load(['customer', 'department', 'workflow']);
                    $ticket = $session->activeQueueTicket ?? $session->queueTickets()->latest('id')->first();

                    return [
                        'queue_session' => $session,
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

        try {
            return $this->processPhysicalIntakeWithinTransaction(
                $tenant, $facility, $department, $channel, $priority,
                $idempotencyKey, $registeredByUserId, $workflow, $registrationStep, $registrationStation,
            );
        } catch (UniqueConstraintViolationException) {
            // Same race as the online path: the unique (tenant_id,
            // idempotency_key) index caught a duplicate the pre-check missed.
            $existingAcq = $idempotencyKey === null
                ? null
                : $this->findAcquisitionForTenant($tenant, $idempotencyKey);

            if ($existingAcq === null) {
                throw new BusinessConflictException('The intake could not be created because an identical one already exists.');
            }

            $session = $existingAcq->queueSession->load(['customer', 'department', 'workflow']);
            $ticket = $session->activeQueueTicket ?? $session->queueTickets()->latest('id')->first();

            return [
                'queue_session' => $session,
                'acquisition' => $existingAcq,
                'ticket' => $ticket,
            ];
        }
    }

    /**
     * The write half of {@see processPhysicalIntake()}, inside one transaction.
     *
     * @return array{queue_session: QueueSession, acquisition: QueueAcquisition, ticket: QueueTicket}
     */
    private function processPhysicalIntakeWithinTransaction(
        Tenant $tenant,
        Facility $facility,
        Department $department,
        IntakeChannel $channel,
        Priority $priority,
        ?string $idempotencyKey,
        ?int $registeredByUserId,
        Workflow $workflow,
        WorkflowStep $registrationStep,
        Station $registrationStation,
    ): array {
        return DB::transaction(function () use (
            $tenant,
            $facility,
            $department,
            $channel,
            $priority,
            $idempotencyKey,
            $registeredByUserId,
            $workflow,
            $registrationStep,
            $registrationStation
        ): array {
            $sessionNumber = $this->sequenceService->generateSessionNumber($facility);
            $trackingToken = (string) Str::uuid();
            $numbering = $this->sequenceService->generateQueueNumber($registrationStation);

            /** @var QueueSession $session */
            $session = QueueSession::query()->create([
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'customer_id' => null,
                'department_id' => $department->id,
                'workflow_id' => $workflow->id,
                'session_number' => $sessionNumber,
                'tracking_token' => $trackingToken,
                'priority' => $priority,
                'intake_channel' => $channel,
                'status' => QueueSessionStatus::Waiting,
                'registered_by_user_id' => $registeredByUserId,
                'checked_in_at' => now(),
            ]);

            /** @var QueueAcquisition $acquisition */
            $acquisition = QueueAcquisition::query()->create([
                'queue_session_id' => $session->id,
                'tenant_id' => $tenant->id,
                'department_id' => $department->id,
                'channel' => $channel,
                'status' => QueueAcquisitionStatus::Acquired,
                'idempotency_key' => $idempotencyKey,
                'registered_by_user_id' => $registeredByUserId,
            ]);

            /** @var QueueTicket $ticket */
            $ticket = QueueTicket::query()->create([
                'queue_session_id' => $session->id,
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
                entityType: 'QueueSession',
                entityId: $session->id,
                metadata: [
                    'channel' => $channel->value,
                    'session_number' => $sessionNumber,
                    'queue_number' => $ticket->queue_number,
                ]
            );

            return [
                'queue_session' => $session->load(['customer', 'department', 'workflow']),
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
    public function getActiveAcquisitionForSession(Tenant $tenant, int $sessionId): ?QueueAcquisition
    {
        return $this->acquisitionsWithin($tenant)
            ->with(['queueSession.customer', 'department', 'queueSession.workflow'])
            ->where('queue_session_id', $sessionId)
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
                'queueSession.customer',
                'department',
                'queueSession.workflow',
                'queueSession.queueTickets.station',
                'queueSession.queueTickets.workflowStep',
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
        ?int $userId = null
    ): QueueAcquisition {
        return DB::transaction(function () use ($tenant, $id, $reason, $userId): QueueAcquisition {
            /** @var QueueAcquisition $acquisition */
            $acquisition = $this->acquisitionsWithin($tenant)
                ->with('queueSession')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($acquisition->status === QueueAcquisitionStatus::Cancelled) {
                throw new InvalidStateException("Acquisition #{$id} is already cancelled.");
            }

            if ($acquisition->status === QueueAcquisitionStatus::Registered) {
                throw new BusinessConflictException("Cannot cancel acquisition #{$id} because registration has already completed. Cancel the queue session instead.");
            }

            $acquisition->update([
                'status' => QueueAcquisitionStatus::Cancelled,
            ]);

            $session = $acquisition->queueSession;
            if ($session && in_array($session->status, [QueueSessionStatus::AwaitingCheckin, QueueSessionStatus::Waiting], true)) {
                $session->update([
                    'status' => QueueSessionStatus::Cancelled,
                ]);

                // Cancel any pending registration tickets
                $activeTickets = QueueTicket::query()
                    ->where('queue_session_id', $session->id)
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
                    'queue_session_id' => $session?->id,
                ],
                userId: $userId
            );

            return $acquisition->fresh(['queueSession.customer', 'department']);
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
            ->whereHas('queueSession', fn($query) => $query->where('tenant_id', $tenant->id));
    }

    /**
     * Find an acquisition by idempotency key inside the current tenant only.
     */
    private function findAcquisitionForTenant(Tenant $tenant, string $idempotencyKey): ?QueueAcquisition
    {
        return $this->acquisitionsWithin($tenant)
            ->with('queueSession')
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
        return QueueSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('online_active_key', $bookingIdentifier);
    }
}
