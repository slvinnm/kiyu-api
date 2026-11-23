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
     * the clinic; platform and system operations leave it null.
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
        ?Tenant $tenant = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenant?->id,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
