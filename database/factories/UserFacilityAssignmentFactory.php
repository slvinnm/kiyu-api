<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserFacilityAssignment>
 */
class UserFacilityAssignmentFactory extends Factory
{
    protected $model = UserFacilityAssignment::class;

    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'user_id' => User::factory(),
            'facility_id' => $facility->id,
            'assigned_by_user_id' => null,
        ];
    }
}
