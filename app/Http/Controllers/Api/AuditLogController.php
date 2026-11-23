<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use HandlesTableQuery;

    /**
     * List audit logs with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'action' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_id' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'string', 'max:255'],
        ]);

        $query = AuditLog::query()->where('tenant_id', $this->tenantId());

        foreach (['action', 'entity_type', 'entity_id', 'user_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('user_id', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $this->applyTableSort($query, $validated, ['action', 'entity_type', 'user_id', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }
}
