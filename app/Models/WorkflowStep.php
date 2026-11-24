<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'station_id',
        'name',
        'sequence',
        'is_active',
        'is_optional',
        'can_skip',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
        'is_optional' => 'boolean',
        'can_skip' => 'boolean',
    ];

    /**
     * Whether a ticket parked on this step may be skipped.
     *
     * An optional step is skippable by definition; a mandatory one only when
     * it opts in with `can_skip`.
     */
    public function isSkippable(): bool
    {
        return $this->is_optional || $this->can_skip;
    }

    /**
     * Get the workflow that owns the step.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the station for this workflow step.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the queue tickets created for this step.
     */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }
}
