<?php

namespace App\Models;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueSessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One service interaction at a facility and department, following a workflow.
 */
class QueueSession extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'customer_id',
        'department_id',
        'workflow_id',
        'session_number',
        'tracking_token',
        'priority',
        'intake_channel',
        'status',
        'online_active_key',
        'registered_by_user_id',
        'checked_in_at',
        'registered_at',
        'completed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * `tracking_token` authorises public tracking of a session without a
     * login, so it must never be serialized into an API response. Callers that
     * need it read it from the model explicitly.
     *
     * `online_active_key` stays visible on purpose: the booking flow needs it
     * to perform the later check-in.
     *
     * @var list<string>
     */
    protected $hidden = [
        'tracking_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'priority' => Priority::class,
        'intake_channel' => IntakeChannel::class,
        'status' => QueueSessionStatus::class,
        'checked_in_at' => 'datetime',
        'registered_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns this session.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the facility hosting this session.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Get the customer who requested this service.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the department served.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the workflow followed.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the latest queue acquisition for this session.
     */
    public function queueAcquisition(): HasOne
    {
        return $this->hasOne(QueueAcquisition::class)->latest('id');
    }

    /**
     * Get all queue acquisitions for this session.
     */
    public function queueAcquisitions(): HasMany
    {
        return $this->hasMany(QueueAcquisition::class);
    }

    /**
     * Get the queue tickets generated for this session.
     */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }

    /**
     * Get the currently active queue ticket, if any.
     */
    public function activeQueueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class)
            ->whereIn('status', ['CREATED', 'CALLED', 'IN_PROGRESS', 'ON_HOLD'])
            ->latest('id');
    }

    /**
     * Get service transfers where this session is the source.
     */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(ServiceTransfer::class, 'source_queue_session_id');
    }
}
