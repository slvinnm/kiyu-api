<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-boxed, reasoned grant for a platform operator to support one tenant.
 *
 * @see \App\Http\Controllers\Api\Platform\SupportAccessController
 */
class SupportAccess extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'platform_user_id',
        'reason',
        'started_at',
        'ended_at',
        'expires_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Whether the grant is currently usable. */
    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_user_id');
    }
}
