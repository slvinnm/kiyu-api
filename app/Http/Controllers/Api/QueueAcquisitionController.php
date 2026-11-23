<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\QueueAcquisition;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueAcquisitionController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        public IntakeService $intakeService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'channel' => ['nullable', 'string', 'in:KIOSK,MANUAL,ONLINE'],
            'status' => ['nullable', 'string', 'in:ACQUIRED,REGISTERED,CANCELLED'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'date' => ['nullable', 'date'],
        ]);

        $query = QueueAcquisition::query()
            ->whereHas('visit', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['visit.client', 'department', 'visit.workflow']);

        if (! empty($validated['channel'])) {
            $query->where('channel', $validated['channel']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        if (! empty($validated['date'])) {
            $this->whereOnDate($query, $validated['date']);
        }

        $this->applyTableSort(
            $query,
            $validated,
            ['id', 'channel', 'status', 'department_id', 'created_at'],
            null,
        );

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $acquisition = $this->intakeService->getAcquisition($this->tenant(), $id);

        return response()->json([
            'message' => 'Queue acquisition retrieved.',
            'data' => $acquisition,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'user_id' => ['nullable', 'string'],
        ]);

        $acquisition = $this->intakeService->cancelAcquisition(
            tenant: $this->tenant(),
            id: $id,
            reason: $validated['reason'] ?? null,
            userId: $validated['user_id'] ?? null
        );

        return response()->json([
            'message' => 'Queue acquisition cancelled.',
            'data' => $acquisition,
        ]);
    }
}
