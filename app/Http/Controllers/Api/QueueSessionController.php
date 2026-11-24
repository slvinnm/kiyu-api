<?php

namespace App\Http\Controllers\Api;

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

class QueueSessionController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        public QueueSessionService $sessionService,
        public IntakeService $intakeService,
    ) {}

    /**
     * List queue sessions with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueSessionStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'intake_channel' => ['nullable', 'string', Rule::in(array_column(IntakeChannel::cases(), 'value'))],
            'date' => ['nullable', 'date'],
        ]);

        // A client may only list their own queue sessions. Staff see every visit their
        // operation permits, unchanged.
        if ($this->isClient($request)) {
            $ownId = $this->customerId($request);

            abort_if($ownId === null, 403, 'Forbidden.');
            abort_if(
                ! empty($validated['customer_id']) && (int) $validated['customer_id'] !== $ownId,
                403,
                'Forbidden.'
            );

            $validated['customer_id'] = $ownId;
        }

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

    /**
     * Show a queue session and all its details (tickets, events, acquisitions, client).
     */
    public function show(Request $request, int $id): JsonResponse
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

        // Clients may only open their own visit.
        if ($this->isClient($request)) {
            abort_unless($session->customer_id === $this->customerId($request), 403, 'Forbidden.');
        }

        return response()->json([
            'data' => $session,
        ]);
    }

    /**
     * Track a queue session by its unique token (used by kiosk and client portal).
     */
    public function track(string $token): JsonResponse
    {
        return response()->json([
            'data' => $this->sessionService->getTrackingData($token),
        ]);
    }

    /**
     * The acquisition awaiting registration for this session (`null` when there
     * is none). Used by reception when completing a registration.
     */
    public function acquisition(Request $request, int $id): JsonResponse
    {
        $session = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        if ($this->isClient($request)) {
            abort_unless($session->customer_id === $this->customerId($request), 403, 'Forbidden.');
        }

        return response()->json([
            'data' => $this->intakeService->getActiveAcquisitionForSession($this->tenant(), $id),
        ]);
    }

    /**
     * Aggregate counts for dashboard widgets.
     */
    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueSessionStatus::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $query = QueueSession::query()->where('tenant_id', $this->tenantId());

        if ($this->isClient($request)) {
            $ownId = $this->customerId($request);

            abort_if($ownId === null, 403, 'Forbidden.');

            $query->where('customer_id', $ownId);
        }

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

    /**
     * Update session priority and propagate to active tickets.
     */
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

    /**
     * Cancel a queue session.
     */
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
