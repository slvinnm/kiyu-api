<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company() . ' Clinic',
            'code' => strtoupper(fake()->unique()->bothify('FAC-####')),
            'address' => fake()->address(),
            'phone' => fake()->numerify('08##########'),
            'timezone' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the facility is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }
}
