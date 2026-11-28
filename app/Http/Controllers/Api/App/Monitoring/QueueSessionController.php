<?php

namespace App\Http\Controllers\Api\App\Monitoring;

use App\Enums\QueueSessionStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\QueueSession;
use App\Services\QueueSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organization-wide queue-session monitoring for the TenantAdmin App.
 *
 * Read-mostly: the tenant admin watches cross-facility activity and may cancel
 * a session, but does not run queue operations (calling tickets). A facility
 * filter is optional; no facility scope is implied.
 */
class QueueSessionController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly QueueSessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(QueueSessionStatus::cases(), 'value'))],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['customer', 'department', 'workflow']);

        foreach (['department_id', 'facility_id', 'status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
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
                'queueTickets.events',
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => $session,
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
        ]);

        $query = QueueSession::query()->where('tenant_id', $this->tenantId());

        if (! empty($validated['facility_id'])) {
            $query->where('facility_id', $validated['facility_id']);
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

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $session = QueueSession::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $cancelled = $this->sessions->cancelSession(
            session: $session,
            reason: $validated['reason'] ?? null,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => 'QueueSession cancelled successfully.',
            'data' => $cancelled,
        ]);
    }
}
