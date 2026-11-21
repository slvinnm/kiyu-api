<?php

namespace App\Http\Controllers\Api;

use App\Enums\IntakeChannel;
use App\Enums\VisitStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Services\IntakeService;
use App\Services\VisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        public VisitService $visitService,
        public IntakeService $intakeService,
    ) {}

    /**
     * List visits with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(VisitStatus::cases(), 'value'))],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'intake_channel' => ['nullable', 'string', Rule::in(array_column(IntakeChannel::cases(), 'value'))],
            'date' => ['nullable', 'date'],
        ]);

        // A patient may only list their own visits. Staff see every visit their
        // operation permits, unchanged.
        if ($this->isPatient($request)) {
            $ownId = $this->patientId($request);

            abort_if($ownId === null, 403, 'Forbidden.');
            abort_if(
                ! empty($validated['patient_id']) && (int) $validated['patient_id'] !== $ownId,
                403,
                'Forbidden.'
            );

            $validated['patient_id'] = $ownId;
        }

        $query = Visit::query()->with(['patient', 'department', 'workflow']);

        foreach (['department_id', 'status', 'patient_id', 'intake_channel'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $this->whereOnDate($query, $validated['date']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('visit_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn($q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('mrn', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort(
            $query,
            $validated,
            ['visit_number', 'status', 'priority', 'intake_channel', 'created_at', 'id'],
        );

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Show a visit and all its details (tickets, events, acquisitions, patient).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $visit = Visit::query()
            ->with([
                'patient',
                'department',
                'workflow',
                'queueAcquisitions',
                'queueTickets.station',
                'queueTickets.workflowStep',
                'queueTickets.events',
                'outgoingReferrals.targetDepartment',
            ])
            ->findOrFail($id);

        // Patients may only open their own visit.
        if ($this->isPatient($request)) {
            abort_unless($visit->patient_id === $this->patientId($request), 403, 'Forbidden.');
        }

        return response()->json([
            'data' => $visit,
        ]);
    }

    /**
     * Track a visit by its unique token (used by kiosk and patient portal).
     */
    public function track(string $token): JsonResponse
    {
        return response()->json([
            'data' => $this->visitService->getTrackingData($token),
        ]);
    }

    /**
     * The acquisition awaiting registration for this visit (`null` when there
     * is none). Used by reception when completing a registration.
     */
    public function acquisition(Request $request, int $id): JsonResponse
    {
        $visit = Visit::query()->findOrFail($id);

        if ($this->isPatient($request)) {
            abort_unless($visit->patient_id === $this->patientId($request), 403, 'Forbidden.');
        }

        return response()->json([
            'data' => $this->intakeService->getActiveAcquisitionForVisit($id),
        ]);
    }

    /**
     * Aggregate counts for dashboard widgets.
     */
    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(array_column(VisitStatus::cases(), 'value'))],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $query = Visit::query();

        if ($this->isPatient($request)) {
            $ownId = $this->patientId($request);

            abort_if($ownId === null, 403, 'Forbidden.');

            $query->where('patient_id', $ownId);
        }

        foreach (['status', 'department_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['date'])) {
            $this->whereOnDate($query, $validated['date']);
        }

        return response()->json([
            'data' => [
                'total' => $query->count(),
            ],
        ]);
    }

    /**
     * Update visit priority and propagate to active tickets.
     */
    public function updatePriority(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'priority' => ['required', 'integer', 'in:1,2,3'],
            'user_id' => ['nullable', 'string'],
        ]);

        $visit = Visit::query()->findOrFail($id);
        $updatedVisit = $this->visitService->updatePriority(
            visit: $visit,
            priority: (int) $validated['priority'],
            userId: $validated['user_id'] ?? null
        );

        return response()->json([
            'message' => "Priority updated to {$updatedVisit->priority->label()} and propagated to active tickets.",
            'data' => $updatedVisit,
        ]);
    }

    /**
     * Cancel a visit.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
            'user_id' => ['nullable', 'string'],
        ]);

        $visit = Visit::query()->findOrFail($id);
        $cancelledVisit = $this->visitService->cancelVisit(
            visit: $visit,
            reason: $validated['reason'] ?? null,
            userId: $validated['user_id'] ?? null
        );

        return response()->json([
            'message' => 'Visit cancelled successfully.',
            'data' => $cancelledVisit,
        ]);
    }
}
