<?php

namespace App\Http\Controllers\Api\App\Configuration;

use App\Enums\QueueTicketStatus;
use App\Enums\StationType;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\Configuration\StationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Station configuration from the TenantAdmin App, inside the facility selected
 * with `X-Facility-Id`.
 */
class StationController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly StationService $stations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'type' => ['nullable', 'string', Rule::in($this->stations->typeValues())],
        ]);

        $query = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->with('department');

        if (! empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhereHas('department', fn($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort(
            $query,
            $validated,
            ['code', 'name', 'type', 'is_active', 'department_id', 'created_at'],
            null,
        );

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'type' => ['nullable', 'string', Rule::in($this->stations->typeValues())],
        ]);

        return response()->json([
            'data' => $this->stations->options($this->facilityId(), $validated),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::enum(StationType::class)],
            'queue_prefix' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $station = $this->stations->create($this->facilityId(), $validated);

        return response()->json([
            'message' => 'Station created successfully.',
            'data' => $station,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->stations->find($this->facilityId(), $id),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['sometimes', 'required', 'integer'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'type' => ['sometimes', 'required', Rule::enum(StationType::class)],
            'queue_prefix' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $station = $this->stations->update($this->facilityId(), $id, $validated);

        return response()->json([
            'message' => 'Station updated successfully.',
            'data' => $station,
        ]);
    }

    public function queue(Request $request, int $id): JsonResponse
    {
        $validated = $this->validateTableQuery($request);

        $station = $this->stations->find($this->facilityId(), $id);

        $tickets = $station->queueTickets()
            ->with(['queueSession.customer', 'workflowStep'])
            ->whereIn('status', [
                QueueTicketStatus::Created,
                QueueTicketStatus::Called,
                QueueTicketStatus::InProgress,
                QueueTicketStatus::OnHold,
            ])
            ->orderByRaw("CASE
                WHEN status = 'IN_PROGRESS' THEN 1
                WHEN status = 'CALLED' THEN 2
                WHEN status = 'CREATED' THEN 3
                ELSE 4 END")
            ->orderBy('priority', 'desc')
            ->orderBy('internal_sequence', 'asc')
            ->paginate($this->tablePerPage($validated));

        return response()->json([
            'station' => $station,
            'data' => $tickets,
        ]);
    }
}
