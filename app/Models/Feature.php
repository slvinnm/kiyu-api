<?php

namespace App\Models;

use App\Enums\FeatureCategory;
use App\Enums\FeatureValueType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of the global feature registry (release switch + operational metadata).
 *
 * The backend {@see \App\Enums\Feature} enum stays the code allowlist; this row
 * only carries the global `is_feature_released` switch and display metadata.
 */
class Feature extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'value_type',
        'is_feature_released',
        'release_note',
        'released_at',
    ];

    protected $casts = [
        'category' => FeatureCategory::class,
        'value_type' => FeatureValueType::class,
        'is_feature_released' => 'boolean',
        'released_at' => 'datetime',
    ];
}
