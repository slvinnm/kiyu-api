<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Exceptions\BusinessConflictException;
use App\Exceptions\InvalidStateException;
use App\Models\Client;
use App\Models\Department;
use App\Models\QueueAcquisition;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\Visit;
use App\Support\LocalDay;
use App\Support\QueueBroadcast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceptionService
{
    public function __construct(
        public WorkflowService $workflowService,
        public SequenceService $sequenceService,
        public AuditService $auditService,
        public ClinicalQueueService $clinicalQueue,
    ) {}

    /**
     * Get the current reception queue state for a department.
     *
     * @return array{station: Station, current_calling: ?QueueTicket, waiting: Collection, waiting_count: int, called_count_today: int, on_hold: Collection}
     */
    public function getReceptionQueue(
        Tenant $tenant,
        int $departmentId,
        ?int $stationId = null,
        ?string $actor = null
    ): array {
        $this->assertDepartmentInTenant($tenant, $departmentId);

        $workflow = $this->workflowService->resolvePrimaryWorkflow($departmentId);
        $registrationStep = $this->workflowService->resolveRegistrationStep($workflow);
        $registrationStation = $registrationStep->station;

        if ($stationId && $stationId !== $registrationStation->id) {
            throw new InvalidStateException("Provided station #{$stationId} does not match active registration station #{$registrationStation->id}.");
        }

        [$dayStart, $dayEnd] = LocalDay::bounds($tenant->timezone);

        // "Current calling" is per-operator: a ticket called by a colleague at
        // the same desk must not appear as this user's current client.
        $currentCalling = QueueTicket::query()
            ->with(['visit.client', 'station', 'workflowStep'])
            ->where('station_id', $registrationStation->id)
            ->where('status', QueueTicketStatus::Called)
            ->when($actor !== null, fn($query) => $query->where('assigned_to', $actor))
            ->latest('called_at')
            ->first();

        // Waiting tickets (ordered by priority desc, internal_sequence asc)
        $waitingTickets = QueueTicket::query()
            ->with(['visit.client', 'station', 'workflowStep'])
            ->where('station_id', $registrationStation->id)
            ->where('status', QueueTicketStatus::Created)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->orderBy('priority', 'desc')
            ->orderBy('internal_sequence', 'asc')
            ->get();

        // On hold tickets (if any)
        $onHoldTickets = QueueTicket::query()
            ->with(['visit.client', 'station', 'workflowStep'])
            ->where('station_id', $registrationStation->id)
            ->where('status', QueueTicketStatus::OnHold)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->get();

        $calledCountToday = QueueTicket::query()
            ->where('station_id', $registrationStation->id)
            ->whereIn('status', [QueueTicketStatus::Called, QueueTicketStatus::Completed, QueueTicketStatus::Skipped, QueueTicketStatus::NoShow])
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->count();

        return [
            'station' => $registrationStation,
            'current_calling' => $currentCalling,
            'waiting' => $waitingTickets,
            'waiting_count' => $waitingTickets->count(),
            'called_count_today' => $calledCountToday,
            'on_hold' => $onHoldTickets,
        ];
    }

    /**
     * Call next queue ticket for reception at the resolved registration station.
     */
    public function callNext(
        Tenant $tenant,
        int $departmentId,
        ?string $assignedTo = null,
        ?int $stationId = null
    ): ?QueueTicket {
        $this->assertDepartmentInTenant($tenant, $departmentId);

        $workflow = $this->workflowService->resolvePrimaryWorkflow($departmentId);
        $registrationStep = $this->workflowService->resolveRegistrationStep($workflow);
        $registrationStation = $registrationStep->station;

        if ($stationId && $stationId !== $registrationStation->id) {
            throw new InvalidStateException("Provided station #{$stationId} does not match active registration station #{$registrationStation->id}.");
        }

        return $this->clinicalQueue->claimNextAt($tenant, $registrationStation, $assignedTo, 'RECEPTION_CALL_NEXT');
    }

    /**
     * Recall a previously called reception ticket (e.g. client did not hear).
     */
    public function recall(Tenant $tenant, int $ticketId, ?string $assignedTo = null): QueueTicket
    {
        $ticket = DB::transaction(function () use ($tenant, $ticketId, $assignedTo): QueueTicket {
            /** @var QueueTicket $ticket */
            $ticket = $this->ticketsWithin($tenant)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status !== QueueTicketStatus::Called) {
                throw new InvalidStateException("Ticket #{$ticket->queue_number} cannot be recalled from status '{$ticket->status->value}'. Must be CALLED.");
            }

            QueueEvent::query()->create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => QueueEventType::Recalled,
                'from_status' => QueueTicketStatus::Called->value,
                'to_status' => QueueTicketStatus::Called->value,
                'user_id' => $assignedTo,
                'payload' => ['reason' => 'Reception recall announced'],
            ]);

            $this->auditService->log(
                action: 'RECEPTION_RECALL',
                entityType: 'QueueTicket',
                entityId: $ticket->id,
                metadata: [
                    'queue_number' => $ticket->queue_number,
                    'station_id' => $ticket->station_id,
                ],
                userId: $assignedTo,
                tenant: $tenant,
            );

            return $ticket->load(['visit.client', 'station', 'workflowStep']);
        });

        if ($ticket) {
            $ticket->loadMissing(['station.department']);

            QueueBroadcast::called($ticket);
        }

        return $ticket;
    }

    /**
     * Skip a reception ticket (client not present, defer).
     *
     * Unlike a clinical skip this does not advance the workflow: the client
     * is still at the desk and will be recalled, so the visit keeps waiting
     * on the registration step.
     */
    public function skip(Tenant $tenant, int $ticketId, ?string $reason = null, ?string $userId = null): QueueTicket
    {
        return DB::transaction(function () use ($tenant, $ticketId, $reason, $userId): QueueTicket {
            /** @var QueueTicket $ticket */
            $ticket = $this->ticketsWithin($tenant)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status !== QueueTicketStatus::Called) {
                throw new InvalidStateException("Reception ticket #{$ticket->queue_number} cannot be skipped from status '{$ticket->status->value}'. Must be CALLED.");
            }

            $ticket->update([
                'status' => QueueTicketStatus::Skipped,
                'notes' => $reason,
            ]);

            QueueEvent::query()->create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => QueueEventType::Skipped,
                'from_status' => QueueTicketStatus::Called->value,
                'to_status' => QueueTicketStatus::Skipped->value,
                'user_id' => $userId,
                'payload' => ['reason' => $reason],
            ]);

            $this->auditService->log(
                action: 'RECEPTION_TICKET_SKIPPED',
                entityType: 'QueueTicket',
                entityId: $ticket->id,
                metadata: ['reason' => $reason],
                userId: $userId,
                tenant: $tenant,
            );

            return $ticket->load(['visit.client', 'station', 'workflowStep']);
        });
    }

    /**
     * Mark a reception ticket as no-show.
     */
    public function noShow(Tenant $tenant, int $ticketId, ?string $userId = null): QueueTicket
    {
        return DB::transaction(function () use ($tenant, $ticketId, $userId): QueueTicket {
            /** @var QueueTicket $ticket */
            $ticket = $this->ticketsWithin($tenant)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status !== QueueTicketStatus::Called) {
                throw new InvalidStateException("Reception ticket cannot be marked no-show from status '{$ticket->status->value}'. Must be CALLED.");
            }

            $ticket->update([
                'status' => QueueTicketStatus::NoShow,
            ]);

            QueueEvent::query()->create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => QueueEventType::NoShow,
                'from_status' => QueueTicketStatus::Called->value,
                'to_status' => QueueTicketStatus::NoShow->value,
                'user_id' => $userId,
            ]);

            $this->auditService->log(
                action: 'RECEPTION_TICKET_NO_SHOW',
                entityType: 'QueueTicket',
                entityId: $ticket->id,
                metadata: [],
                userId: $userId,
                tenant: $tenant,
            );

            return $ticket->load(['visit.client', 'station', 'workflowStep']);
        });
    }

    /**
     * Complete registration process atomically.
     *
     * @param  array<string, mixed>|null  $clientData
     * @return array{visit: Visit, acquisition: QueueAcquisition, registration_ticket: QueueTicket, next_ticket: ?QueueTicket}
     */
    public function completeRegistration(
        Tenant $tenant,
        int $acquisitionId,
        ?int $clientId = null,
        ?array $clientData = null,
        ?int $priority = null,
        ?string $registeredBy = null
    ): array {
        return DB::transaction(function () use ($tenant, $acquisitionId, $clientId, $clientData, $priority, $registeredBy): array {
            /** @var QueueAcquisition|null $acquisition */
            $acquisition = QueueAcquisition::query()
                ->whereHas('visit', fn($q) => $q->where('tenant_id', $tenant->id))
                ->with(['visit.workflow', 'visit.client'])
                ->lockForUpdate()
                ->find($acquisitionId);

            if (! $acquisition) {
                throw new InvalidStateException("Queue acquisition #{$acquisitionId} not found.");
            }

            if ($acquisition->status === QueueAcquisitionStatus::Registered) {
                throw new BusinessConflictException("Registration already completed for acquisition #{$acquisitionId}.");
            }

            if ($acquisition->status === QueueAcquisitionStatus::Cancelled) {
                throw new InvalidStateException("Acquisition #{$acquisitionId} has been cancelled.");
            }

            /** @var Visit $visit */
            $visit = $acquisition->visit;

            // Resolve or link client
            $resolvedClient = null;
            if ($clientId) {
                $resolvedClient = Client::query()
                    ->where('tenant_id', $tenant->id)
                    ->findOrFail($clientId);
            } elseif ($clientData) {
                $nik = $clientData['nik'] ?? null;
                if ($nik) {
                    $existing = Client::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('nik', $nik)
                        ->first();
                    if ($existing) {
                        throw new BusinessConflictException("NIK '{$nik}' already exists for client '{$existing->name}' (MRN: {$existing->mrn}). Link existing client or use different NIK.");
                    }
                }
                $mrn = $clientData['mrn'] ?? ('MRN-' . strtoupper(Str::random(8)));

                $resolvedClient = new Client([
                    'mrn' => $mrn,
                    'nik' => $clientData['nik'] ?? null,
                    'name' => $clientData['name'],
                    'date_of_birth' => $clientData['date_of_birth'] ?? null,
                    'gender' => $clientData['gender'] ?? null,
                    'phone' => $clientData['phone'] ?? null,
                    'email' => $clientData['email'] ?? null,
                    'address' => $clientData['address'] ?? null,
                ]);
                $resolvedClient->tenant_id = $tenant->id;
                $resolvedClient->save();
            } elseif ($visit->client_id) {
                $resolvedClient = $visit->client;
            }

            if (! $resolvedClient) {
                throw new InvalidStateException('Client identification is required to complete registration.');
            }

            $updateData = [
                'client_id' => $resolvedClient->id,
                'registered_by' => $registeredBy,
                'registered_at' => now(),
            ];

            if ($priority) {
                $newPriority = Priority::tryFrom($priority);
                if ($newPriority) {
                    $updateData['priority'] = $newPriority;
                }
            }

            $visit->update($updateData);

            // Complete registration QueueTicket
            /** @var QueueTicket|null $regTicket */
            $regTicket = QueueTicket::query()
                ->where('visit_id', $visit->id)
                ->whereIn('status', [QueueTicketStatus::Created, QueueTicketStatus::Called, QueueTicketStatus::InProgress])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $regTicket) {
                throw new InvalidStateException("Active registration ticket not found for visit #{$visit->id}.");
            }

            $prevStatus = $regTicket->status;
            $regTicket->update([
                'status' => QueueTicketStatus::Completed,
                'completed_at' => now(),
            ]);

            QueueEvent::query()->create([
                'queue_ticket_id' => $regTicket->id,
                'event_type' => QueueEventType::Completed,
                'from_status' => $prevStatus->value,
                'to_status' => QueueTicketStatus::Completed->value,
                'user_id' => $registeredBy,
                'payload' => ['reason' => 'Reception registration completed'],
            ]);

            // Update QueueAcquisition
            $acquisition->update([
                'status' => QueueAcquisitionStatus::Registered,
                'registered_by' => $registeredBy,
            ]);

            // Resolve next workflow step
            $workflow = $visit->workflow;
            $nextStep = $this->workflowService->resolveNextStep($workflow, $regTicket->workflowStep->sequence);

            $nextTicket = null;
            if ($nextStep) {
                $nextStation = $nextStep->station;
                $numbering = $this->sequenceService->generateQueueNumber($nextStation);

                /** @var QueueTicket $nextTicket */
                $nextTicket = QueueTicket::query()->create([
                    'visit_id' => $visit->id,
                    'workflow_step_id' => $nextStep->id,
                    'station_id' => $nextStation->id,
                    'queue_number' => $numbering['queue_number'],
                    'priority' => $visit->priority,
                    'internal_sequence' => $numbering['internal_sequence'],
                    'status' => QueueTicketStatus::Created,
                ]);

                QueueEvent::query()->create([
                    'queue_ticket_id' => $nextTicket->id,
                    'event_type' => QueueEventType::Created,
                    'from_status' => null,
                    'to_status' => QueueTicketStatus::Created->value,
                    'user_id' => $registeredBy,
                    'payload' => ['reason' => 'Clinical handoff after registration'],
                ]);
            } else {
                // No next step in workflow -> visit completed
                $visit->update([
                    'status' => VisitStatus::Completed,
                    'completed_at' => now(),
                ]);
            }

            $this->auditService->log(
                action: 'REGISTRATION_COMPLETED',
                entityType: 'QueueAcquisition',
                entityId: $acquisition->id,
                metadata: [
                    'visit_id' => $visit->id,
                    'client_id' => $resolvedClient->id,
                    'next_ticket_id' => $nextTicket?->id,
                    'next_station_id' => $nextTicket?->station_id,
                ],
                userId: $registeredBy,
                tenant: $tenant,
            );

            return [
                'visit' => $visit->fresh(['client', 'department', 'workflow']),
                'acquisition' => $acquisition->fresh(),
                'registration_ticket' => $regTicket->fresh(['station', 'workflowStep']),
                'next_ticket' => $nextTicket?->load(['station', 'workflowStep']),
            ];
        });
    }

    /**
     * Ensure a department belongs to the current tenant (and facility).
     */
    private function assertDepartmentInTenant(Tenant $tenant, int $departmentId): void
    {
        $exists = Department::query()
            ->whereKey($departmentId)
            ->whereHas('facility', fn($q) => $q->where('tenant_id', $tenant->id))
            ->exists();

        if (! $exists) {
            throw new InvalidStateException("Department #{$departmentId} does not belong to the current tenant.");
        }
    }

    /**
     * Scope a ticket lookup to the current tenant through its visit.
     */
    private function ticketsWithin(Tenant $tenant)
    {
        return QueueTicket::query()
            ->whereHas('visit', fn($q) => $q->where('tenant_id', $tenant->id));
    }
}
