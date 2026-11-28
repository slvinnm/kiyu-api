<?php

namespace Database\Seeders;

use App\Enums\Feature as FeatureCode;
use App\Models\Feature;
use Illuminate\Database\Seeder;

/**
 * Register every backend feature code in the release registry.
 *
 * Every known code is seeded `is_feature_released = true` per product decision.
 * A feature whose implementation is not finished must be flipped to `false`
 * explicitly before its routes are exposed.
 */
class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        foreach (FeatureCode::cases() as $feature) {
            Feature::query()->updateOrCreate(
                ['code' => $feature->value],
                [
                    'name' => $this->humanName($feature),
                    'description' => null,
                    'category' => $feature->category()->value,
                    'value_type' => $feature->valueType()->value,
                ],
            );
        }
    }

    private function humanName(FeatureCode $feature): string
    {
        return ucwords(str_replace('_', ' ', $feature->value));
    }
}
