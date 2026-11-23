<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReferralStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferralController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        public ReferralService $referralService
    ) {}

    /**
     * List referrals.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'source_visit_id' => ['nullable', 'integer', 'exists:visits,id'],
            'target_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', Rule::in(array_column(ReferralStatus::cases(), 'value'))],
        ]);

        $query = Referral::query()
            ->whereHas('sourceVisit', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['sourceVisit.client', 'targetDepartment', 'targetVisit']);

        foreach (['source_visit_id', 'target_department_id', 'status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('sourceVisit.client', fn($q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('mrn', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort($query, $validated, ['status', 'priority', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Create a new referral to another department.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_visit_id' => ['required', 'integer', 'exists:visits,id'],
            'target_department_id' => ['required', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', 'integer', 'in:1,2,3'],
            'reason' => ['nullable', 'string'],
            'referred_by' => ['nullable', 'string'],
        ]);

        $result = $this->referralService->createReferral(
            tenant: $this->tenant(),
            sourceVisitId: (int) $validated['source_visit_id'],
            targetDepartmentId: (int) $validated['target_department_id'],
            priority: isset($validated['priority']) ? (int) $validated['priority'] : null,
            reason: $validated['reason'] ?? null,
            referredBy: $validated['referred_by'] ?? null
        );

        return response()->json([
            'message' => 'Referral created and target queue ticket generated.',
            'data' => $result,
        ], 201);
    }

    /**
     * Show a referral.
     */
    public function show(int $id): JsonResponse
    {
        $referral = Referral::query()
            ->whereHas('sourceVisit', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['sourceVisit.client', 'targetDepartment', 'targetVisit.queueTickets'])
            ->findOrFail($id);

        return response()->json(['data' => $referral]);
    }
}
