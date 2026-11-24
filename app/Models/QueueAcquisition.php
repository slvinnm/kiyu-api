<?php

namespace App\Models;

use App\Enums\IntakeChannel;
use App\Enums\QueueAcquisitionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueAcquisition extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_session_id',
        'tenant_id',
        'department_id',
        'channel',
        'status',
        'idempotency_key',
        'registered_by_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'channel' => IntakeChannel::class,
        'status' => QueueAcquisitionStatus::class,
    ];

    /**
     * Get the queue session this acquisition belongs to.
     */
    public function queueSession(): BelongsTo
    {
        return $this->belongsTo(QueueSession::class);
    }

    /**
     * Get the department this acquisition was taken for.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the user who registered the acquisition, if any.
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }
}
