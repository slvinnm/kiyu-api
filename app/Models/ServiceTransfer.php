<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\ServiceTransferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hand-off of a queue session to another department. General replacement for
 * the former `referrals` table: creating one starts a new queue
 * session (and its first ticket) in the target department.
 */
class ServiceTransfer extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'source_queue_session_id',
        'target_department_id',
        'target_queue_session_id',
        'referred_by_user_id',
        'status',
        'priority',
        'reason',
    ];

    /**
     * Derive tenant ownership from the source session when not given, so a
     * transfer can never belong to a tenant that does not own its source.
     */
    protected static function booted(): void
    {
        static::creating(function (ServiceTransfer $transfer): void {
            if ($transfer->tenant_id === null && $transfer->source_queue_session_id !== null) {
                $transfer->tenant_id = QueueSession::query()
                    ->whereKey($transfer->source_queue_session_id)
                    ->value('tenant_id');
            }
        });
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ServiceTransferStatus::class,
        'priority' => Priority::class,
    ];

    /**
     * Get the queue session that initiated the transfer.
     */
    public function sourceQueueSession(): BelongsTo
    {
        return $this->belongsTo(QueueSession::class, 'source_queue_session_id');
    }

    /**
     * Get the destination department.
     */
    public function targetDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'target_department_id');
    }

    /**
     * Get the queue session created in the destination department, if any.
     */
    public function targetQueueSession(): BelongsTo
    {
        return $this->belongsTo(QueueSession::class, 'target_queue_session_id');
    }
}
