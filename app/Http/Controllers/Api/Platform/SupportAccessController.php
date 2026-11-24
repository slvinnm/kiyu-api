<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\SupportAccess;
use App\Models\Tenant;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support access grants (PRD §10.7).
 *
 * A platform operator must state a reason and a duration; the grant is recorded
 * with the actor, target tenant, start and expiry, and can be ended explicitly.
 * Nothing here bypasses tenant authorization or plan entitlements — it is an
 * audit-recorded consent, not an impersonation token.
 */
class SupportAccessController extends Controller
{
    use HandlesTableQuery;

    /** Upper bound on a single grant. */
    private const MAX_HOURS = 8;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'active' => ['nullable', 'boolean'],
        ]);

        $query = SupportAccess::query()
            ->with(['tenant:id,name,slug', 'platformUser:id,name,email']);

        if (! empty($validated['tenant_id'])) {
            $query->where('tenant_id', $validated['tenant_id']);
        }

        if (($validated['active'] ?? null) === true) {
            $query->whereNull('ended_at')->where('expires_at', '>', now());
        }

        $this->applyTableSort($query, $validated, ['started_at', 'ended_at', 'expires_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'reason' => ['required', 'string', 'max:255'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_HOURS],
        ]);

        $tenant = Tenant::query()->findOrFail($validated['tenant_id']);
        $hours = (int) ($validated['hours'] ?? 1);

        $access = SupportAccess::query()->create([
            'tenant_id' => $tenant->id,
            'platform_user_id' => $request->user()?->id,
            'reason' => $validated['reason'],
            'started_at' => now(),
            'expires_at' => now()->addHours($hours),
        ]);

        $this->audit->log(
            action: 'platform.support_access.started',
            entityType: 'tenant',
            entityId: $tenant->id,
            metadata: ['reason' => $validated['reason'], 'hours' => $hours, 'support_access_id' => $access->id],
            platformUserId: $request->user()?->id,
            tenant: $tenant,
        );

        return response()->json([
            'message' => 'Support access started.',
            'data' => $access->load('tenant:id,name,slug'),
        ], 201);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $access = SupportAccess::query()->findOrFail($id);

        if ($access->ended_at !== null) {
            return response()->json([
                'message' => 'Support access already ended.',
                'data' => $access,
            ]);
        }

        $access->update(['ended_at' => now()]);

        $this->audit->log(
            action: 'platform.support_access.ended',
            entityType: 'tenant',
            entityId: $access->tenant_id,
            metadata: ['support_access_id' => $access->id],
            platformUserId: $request->user()?->id,
            tenant: $access->tenant_id,
        );

        return response()->json([
            'message' => 'Support access ended.',
            'data' => $access->fresh(),
        ]);
    }
}
