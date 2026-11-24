<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitCounter extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'facility_id',
        'counter_date',
        'last_sequence',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'counter_date' => 'string',
        'last_sequence' => 'integer',
    ];

    /**
     * Get the facility this counter belongs to.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
