<?php

namespace App\Http\Controllers\Api;

use App\Enums\QueueTicketStatus;
use App\Enums\StationType;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    use HandlesTableQuery;

    /**
     * List all stations.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'type' => ['nullable', 'string', Rule::in(array_column(StationType::cases(), 'value'))],
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

    /**
     * Lightweight station choices for select inputs, optionally filtered.
     */
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'type' => ['nullable', 'string', Rule::in(array_column(StationType::cases(), 'value'))],
        ]);

        $query = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->orderBy('name');

        if (! empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        return response()->json([
            'data' => $query->get([
                'id',
                'department_id',
                'code',
                'name',
                'type',
                'queue_prefix',
                'is_active',
            ]),
        ]);
    }

    /**
     * Create a station.
     */
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

        // The department must belong to the current tenant's facility.
        Department::query()
            ->where('facility_id', $this->facilityId())
            ->findOrFail($validated['department_id']);

        $station = Station::query()->create([
            'department_id' => $validated['department_id'],
            'name' => $validated['name'],
            'code' => $validated['code'],
            'type' => $validated['type'],
            'queue_prefix' => $validated['queue_prefix'] ?? 'A',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Station created successfully.',
            'data' => $station->load('department'),
        ], 201);
    }

    /**
     * Show a station.
     */
    public function show(int $id): JsonResponse
    {
        $station = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->with('department')
            ->findOrFail($id);

        return response()->json([
            'data' => $station,
        ]);
    }

    /**
     * Update a station.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $station = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->findOrFail($id);

        $validated = $request->validate([
            'department_id' => ['sometimes', 'required', 'integer'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'type' => ['sometimes', 'required', Rule::enum(StationType::class)],
            'queue_prefix' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A station may only move inside the current facility; a bare integer
        // rule would let it be re-parented into another tenant's department.
        if (isset($validated['department_id'])) {
            Department::query()
                ->where('facility_id', $this->facilityId())
                ->findOrFail($validated['department_id']);
        }

        $station->update($validated);

        return response()->json([
            'message' => 'Station updated successfully.',
            'data' => $station->load('department'),
        ]);
    }

    /**
     * View the operational queue for this station.
     */
    public function queue(Request $request, int $id): JsonResponse
    {
        $validated = $this->validateTableQuery($request);

        $station = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->findOrFail($id);

        $tickets = $station->queueTickets()
            ->with(['visit.client', 'workflowStep'])
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

        // `station` sits beside `data` on purpose: the queue screen needs the
        // station header and its paginated tickets in one response.
        return response()->json([
            'station' => $station,
            'data' => $tickets,
        ]);
    }
}
