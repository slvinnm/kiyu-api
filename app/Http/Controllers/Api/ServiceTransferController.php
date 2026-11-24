<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServiceTransferStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\ServiceTransfer;
use App\Services\ServiceTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceTransferController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        public ServiceTransferService $serviceTransferService
    ) {}

    /**
     * List service_transfers.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'source_queue_session_id' => ['nullable', 'integer', 'exists:queue_sessions,id'],
            'target_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(ServiceTransferStatus::cases(), 'value'))],
        ]);

        $query = ServiceTransfer::query()
            ->whereHas('sourceQueueSession', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['sourceQueueSession.customer', 'targetDepartment', 'targetQueueSession']);

        foreach (['source_queue_session_id', 'target_department_id', 'status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('sourceQueueSession.customer', fn($q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort($query, $validated, ['status', 'priority', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Create a new service transfer to another department.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_queue_session_id' => ['required', 'integer', $this->queueSessionRule()],
            'target_department_id' => ['required', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'reason' => ['nullable', 'string'],
        ]);

        $result = $this->serviceTransferService->createTransfer(
            tenant: $this->tenant(),
            sourceSessionId: (int) $validated['source_queue_session_id'],
            targetDepartmentId: (int) $validated['target_department_id'],
            priority: isset($validated['priority']) ? (int) $validated['priority'] : null,
            reason: $validated['reason'] ?? null,
            userId: $this->actor($request),
        );

        return response()->json([
            'message' => 'ServiceTransfer created and target queue ticket generated.',
            'data' => $result,
        ], 201);
    }

    /**
     * Show a service transfer.
     */
    public function show(int $id): JsonResponse
    {
        $transfer = ServiceTransfer::query()
            ->whereHas('sourceQueueSession', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['sourceQueueSession.customer', 'targetDepartment', 'targetQueueSession.queueTickets'])
            ->findOrFail($id);

        return response()->json(['data' => $transfer]);
    }
}
