<?php

namespace App\Http\Controllers\Api\Tenant\Queue;

use App\Enums\IntakeChannel;
use App\Enums\QueueSessionStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\QueueSession;
use App\Services\IntakeService;
use App\Services\QueueSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Queue sessions inside a FacilityAdmin/Operator/Staff workspace.
 *
 * Customers no longer authenticate here (they use the Customer context), so
 * every caller is internal staff and sees all visits their facility scope
 * permits.
 */
class QueueSessionController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        public QueueSessionService $sessionService,
        public IntakeService $intakeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueSessionStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'intake_channel' => ['nullable', 'string', Rule::in(array_column(IntakeChannel::cases(), 'value'))],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['customer', 'department', 'workflow']);

        foreach (['department_id', 'status', 'customer_id', 'intake_channel'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('session_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn($q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort(
            $query,
            $validated,
            ['session_number', 'status', 'priority', 'intake_channel', 'created_at', 'id'],
        );

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $session = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->with([
                'customer',
                'department',
                'workflow',
                'queueAcquisitions',
                'queueTickets.station',
                'queueTickets.workflowStep',
                'queueTickets.events',
                'outgoingTransfers.targetDepartment',
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => $session,
        ]);
    }

    public function acquisition(int $id): JsonResponse
    {
        QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        return response()->json([
            'data' => $this->intakeService->getActiveAcquisitionForSession($this->tenant(), $id),
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueSessionStatus::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $query = QueueSession::query()->where('tenant_id', $this->tenantId());

        foreach (['status', 'department_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        }

        return response()->json([
            'data' => [
                'total' => $query->count(),
            ],
        ]);
    }

    public function updatePriority(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'priority' => ['required', 'integer', 'in:1,2,3'],
        ]);

        $session = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $updatedSession = $this->sessionService->updatePriority(
            session: $session,
            priority: (int) $validated['priority'],
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => "Priority updated to {$updatedSession->priority->label()} and propagated to active tickets.",
            'data' => $updatedSession,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $session = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $cancelledSession = $this->sessionService->cancelSession(
            session: $session,
            reason: $validated['reason'] ?? null,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => 'QueueSession cancelled successfully.',
            'data' => $cancelledSession,
        ]);
    }
}
