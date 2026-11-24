<?php

namespace App\Services;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueEventType;
use App\Enums\QueueSessionStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServiceTransferStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Department;
use App\Models\QueueEvent;
use App\Models\QueueSession;
use App\Models\QueueTicket;
use App\Models\ServiceTransfer;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hands a queue session off to another department.
 *
 * A transfer starts a brand-new queue session (and its first ticket) in the
 * target department, copying the customer and priority. It is distinct from a
 * ticket-level transfer, which keeps the session and moves one ticket to
 * another station.
 */
class ServiceTransferService
{
    public function __construct(
        public SequenceService $sequenceService,
        public WorkflowService $workflowService,
        public AuditService $auditService
    ) {}

    /**
     * Create a transfer from a source session to a target department.
     *
     * @return array{transfer: ServiceTransfer, target_session: QueueSession, target_ticket: QueueTicket}
     */
    public function createTransfer(
        Tenant $tenant,
        int $sourceSessionId,
        int $targetDepartmentId,
        ?int $priority = null,
        ?string $reason = null,
        ?int $userId = null
    ): array {
        $sourceSession = QueueSession::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($sourceSessionId);

        if ($sourceSession->department_id === $targetDepartmentId) {
            throw new InvalidStateException('Target department for a service transfer cannot be the source session department. Use a ticket transfer instead.');
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
            $sourceSession,
            $targetDepartmentId,
            $targetFacility,
            $targetWorkflow,
            $firstStep,
            $priority,
            $reason,
            $userId
        ): array {
            $effectivePriority = $priority ? (Priority::tryFrom($priority) ?? Priority::Normal) : $sourceSession->priority;
            $targetSessionNumber = $this->sequenceService->generateSessionNumber($targetFacility);
            $trackingToken = (string) Str::uuid();

            /** @var QueueSession $targetSession */
            $targetSession = QueueSession::query()->create([
                'tenant_id' => $tenant->id,
                'facility_id' => $targetFacility->id,
                'customer_id' => $sourceSession->customer_id,
                'department_id' => $targetDepartmentId,
                'workflow_id' => $targetWorkflow->id,
                'session_number' => $targetSessionNumber,
                'tracking_token' => $trackingToken,
                'priority' => $effectivePriority,
                'intake_channel' => IntakeChannel::Manual,
                'status' => QueueSessionStatus::Waiting,
                'registered_by_user_id' => $userId,
            ]);

            /** @var ServiceTransfer $transfer */
            $transfer = ServiceTransfer::query()->create([
                'tenant_id' => $tenant->id,
                'source_queue_session_id' => $sourceSession->id,
                'target_department_id' => $targetDepartmentId,
                'target_queue_session_id' => $targetSession->id,
                'referred_by_user_id' => $userId,
                'status' => ServiceTransferStatus::Accepted,
                'priority' => $effectivePriority,
                'reason' => $reason,
            ]);

            $targetStation = $firstStep->station;
            $numbering = $this->sequenceService->generateQueueNumber($targetStation);

            /** @var QueueTicket $targetTicket */
            $targetTicket = QueueTicket::query()->create([
                'queue_session_id' => $targetSession->id,
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
                'user_id' => $userId,
                'payload' => [
                    'reason' => 'Service transfer from session #' . $sourceSession->session_number,
                    'service_transfer_id' => $transfer->id,
                ],
            ]);

            $this->auditService->log(
                action: 'SERVICE_TRANSFER_CREATED',
                entityType: 'ServiceTransfer',
                entityId: $transfer->id,
                metadata: [
                    'source_queue_session_id' => $sourceSession->id,
                    'target_department_id' => $targetDepartmentId,
                    'target_queue_session_id' => $targetSession->id,
                ],
                userId: $userId,
                tenant: $tenant,
            );

            return [
                'transfer' => $transfer->load(['sourceQueueSession', 'targetDepartment', 'targetQueueSession']),
                'target_session' => $targetSession->load(['customer', 'department', 'workflow']),
                'target_ticket' => $targetTicket->load(['station', 'workflowStep']),
            ];
        });
    }
}
