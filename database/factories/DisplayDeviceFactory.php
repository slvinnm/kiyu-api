<?php

namespace Database\Factories;

use App\Models\DisplayDevice;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DisplayDevice>
 */
class DisplayDeviceFactory extends Factory
{
    protected $model = DisplayDevice::class;

    /**
     * Define the model's default state.
     *
     * A factory display is operational by default: it has completed pairing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->id,
            'name' => 'Display ' . fake()->unique()->numerify('##'),
            'credential_hash' => hash('sha256', Str::random(64)),
            'paired_at' => now(),
            'is_active' => true,
            'last_seen_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Attach the display to an existing facility, inheriting its tenant.
     */
    public function forFacility(Facility $facility): static
    {
        return $this->state(fn(array $attributes) => [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->id,
        ]);
    }

    /**
     * Indicate that the display has not redeemed its pairing code yet.
     */
    public function awaitingPairing(): static
    {
        return $this->state(fn(array $attributes) => [
            'paired_at' => null,
            'pairing_code_hash' => hash('sha256', 'PAIRING00'),
            'pairing_expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * Indicate that the display credential is revoked.
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }
}
