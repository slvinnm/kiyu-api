<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform actor audit log (PRD §10.8).
 *
 * Shows platform-originated actions (tenant lifecycle, plan changes, support
 * access). It is platform-wide; tenant-scoped audit stays in the tenant API.
 */
class AuditLogController extends Controller
{
    use HandlesTableQuery;

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'action' => ['nullable', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'platform_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Platform actions are attributed to a platform user; a tenant action
        // written by a platform operator during support carries that actor too.
        $query = AuditLog::query()
            ->with(['tenant:id,name,slug', 'platformUser:id,name,email'])
            ->whereNotNull('platform_user_id');

        foreach (['action', 'tenant_id', 'platform_user_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort($query, $validated, ['action', 'entity_type', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }
}
