<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\KioskDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KioskDevice>
 */
class KioskDeviceFactory extends Factory
{
    protected $model = KioskDevice::class;

    /**
     * Define the model's default state.
     *
     * A device always belongs to exactly one facility, and its tenant is the
     * facility's tenant. When no facility is supplied, one is provisioned with
     * its own tenant so the pairing can never disagree.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->id,
            'name' => 'Kiosk ' . fake()->unique()->numerify('##'),
            'credential_hash' => hash('sha256', Str::random(64)),
            // A factory device is operational by default: it has completed
            // pairing, so its credential is live.
            'paired_at' => now(),
            'is_active' => true,
            'last_seen_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Attach the device to an existing facility, inheriting its tenant.
     */
    public function forFacility(Facility $facility): static
    {
        return $this->state(fn(array $attributes) => [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->id,
        ]);
    }

    /**
     * Indicate that the device is registered but has not redeemed its pairing
     * code, so its credential must never authenticate.
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
     * Indicate that the device credential is revoked.
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }
}
