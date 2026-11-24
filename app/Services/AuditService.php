<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tenant;

class AuditService
{
    /**
     * Record an audit log entry for significant business operations.
     *
     * Tenant-originated operations pass their tenant so the row is scoped to
     * the clinic; platform and system operations leave it null. A bare tenant
     * id is accepted too, so callers holding a ticket or visit do not have to
     * load the tenant model just to log.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        string $action,
        string $entityType,
        string|int $entityId,
        ?array $metadata = null,
        ?string $userId = null,
        ?string $ipAddress = null,
        Tenant|int|null $tenant = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
