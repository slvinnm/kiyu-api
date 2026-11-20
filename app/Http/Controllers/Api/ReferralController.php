<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(
        public ReferralService $referralService
    ) {}

    /**
     * List referrals.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Referral::query()->with(['sourceVisit.patient', 'targetDepartment', 'targetVisit']);

        if ($request->has('source_visit_id')) {
            $query->where('source_visit_id', $request->input('source_visit_id'));
        }

        if ($request->has('target_department_id')) {
            $query->where('target_department_id', $request->input('target_department_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $sort = $request->input('sort');
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['status', 'priority', 'created_at', 'id'];
        if (is_string($sort) && in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $direction);
        } else {
            $query->latest('id');
        }

        return response()->json([
            'data' => $query->paginate($request->input('per_page', 20)),
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
            ->with(['sourceVisit.patient', 'targetDepartment', 'targetVisit.queueTickets'])
            ->findOrFail($id);

        return response()->json(['data' => $referral]);
    }
}
