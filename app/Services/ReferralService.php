<?php

namespace App\Services;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\ReferralStatus;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Department;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Referral;
use App\Models\Tenant;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralService
{
    public function __construct(
        public SequenceService $sequenceService,
        public WorkflowService $workflowService,
        public AuditService $auditService
    ) {}

    /**
     * Create a referral from a source visit to a target department.
     *
     * @return array{referral: Referral, target_visit: Visit, target_ticket: QueueTicket}
     */
    public function createReferral(
        Tenant $tenant,
        int $sourceVisitId,
        int $targetDepartmentId,
        ?int $priority = null,
        ?string $reason = null,
        ?string $referredBy = null
    ): array {
        $sourceVisit = Visit::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($sourceVisitId);

        if ($sourceVisit->department_id === $targetDepartmentId) {
            throw new InvalidStateException('Target department for referral cannot be the same as source visit department. Use transfer instead.');
        }

        // The target department must belong to the same tenant. It may live in
        // another facility owned by that tenant.
        $targetDepartment = Department::query()
            ->whereKey($targetDepartmentId)
            ->whereHas('facility', fn($query) => $query->where('tenant_id', $tenant->id))
            ->firstOrFail();

        if (! $targetDepartment->is_active) {
            throw new InvalidStateException("Target department #{$targetDepartmentId} is inactive.");
        }

        $targetFacility = $targetDepartment->facility;
        if ($targetFacility === null) {
            throw new InvalidStateException("Target department #{$targetDepartmentId} has no facility.");
        }

        $targetWorkflow = $this->workflowService->resolvePrimaryWorkflow($targetDepartmentId);
        $firstStep = $targetWorkflow->activeSteps()->first();

        if (! $firstStep) {
            throw new InvalidStateException("Target workflow #{$targetWorkflow->id} has no active steps.");
        }

        return DB::transaction(function () use (
            $tenant,
            $sourceVisit,
            $targetDepartmentId,
            $targetFacility,
            $targetWorkflow,
            $firstStep,
            $priority,
            $reason,
            $referredBy
        ): array {
            $effectivePriority = $priority ? (Priority::tryFrom($priority) ?? Priority::Normal) : $sourceVisit->priority;
            $targetVisitNumber = $this->sequenceService->generateVisitNumber($targetFacility);
            $visitToken = (string) Str::uuid();

            /** @var Visit $targetVisit */
            $targetVisit = Visit::query()->create([
                'tenant_id' => $tenant->id,
                'facility_id' => $targetFacility->id,
                'client_id' => $sourceVisit->client_id,
                'department_id' => $targetDepartmentId,
                'workflow_id' => $targetWorkflow->id,
                'visit_number' => $targetVisitNumber,
                'visit_token' => $visitToken,
                'priority' => $effectivePriority,
                'intake_channel' => IntakeChannel::Manual,
                'status' => VisitStatus::Waiting,
                'registered_by' => $referredBy,
            ]);

            /** @var Referral $referral */
            $referral = Referral::query()->create([
                'source_visit_id' => $sourceVisit->id,
                'target_department_id' => $targetDepartmentId,
                'target_visit_id' => $targetVisit->id,
                'referred_by_user_id' => $referredBy,
                'status' => ReferralStatus::Accepted,
                'priority' => $effectivePriority,
                'reason' => $reason,
            ]);

            $targetStation = $firstStep->station;
            $numbering = $this->sequenceService->generateQueueNumber($targetStation);

            /** @var QueueTicket $targetTicket */
            $targetTicket = QueueTicket::query()->create([
                'visit_id' => $targetVisit->id,
                'workflow_step_id' => $firstStep->id,
                'station_id' => $targetStation->id,
                'queue_number' => $numbering['queue_number'],
                'priority' => $effectivePriority,
                'internal_sequence' => $numbering['internal_sequence'],
                'status' => QueueTicketStatus::Created,
            ]);

            QueueEvent::query()->create([
                'queue_ticket_id' => $targetTicket->id,
                'event_type' => QueueEventType::Created,
                'from_status' => null,
                'to_status' => QueueTicketStatus::Created->value,
                'user_id' => $referredBy,
                'payload' => [
                    'reason' => 'Referral created from visit #' . $sourceVisit->visit_number,
                    'referral_id' => $referral->id,
                ],
            ]);

            $this->auditService->log(
                action: 'REFERRAL_CREATED',
                entityType: 'Referral',
                entityId: $referral->id,
                metadata: [
                    'source_visit_id' => $sourceVisit->id,
                    'target_department_id' => $targetDepartmentId,
                    'target_visit_id' => $targetVisit->id,
                ],
                userId: $referredBy,
                tenant: $tenant,
            );

            return [
                'referral' => $referral->load(['sourceVisit', 'targetDepartment', 'targetVisit']),
                'target_visit' => $targetVisit->load(['client', 'department', 'workflow']),
                'target_ticket' => $targetTicket->load(['station', 'workflowStep']),
            ];
        });
    }
}
