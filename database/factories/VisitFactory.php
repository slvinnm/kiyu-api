<?php

namespace Database\Factories;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\VisitStatus;
use App\Models\Client;
use App\Models\Facility;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'facility_id' => null,
            'client_id' => Client::factory(),
            'department_id' => 1,
            'workflow_id' => 1,
            'visit_number' => 'V-' . now()->format('Ymd') . '-' . str_pad((string) fake()->numberBetween(1, 999), 4, '0', STR_PAD_LEFT),
            'visit_token' => (string) Str::uuid(),
            'priority' => Priority::Normal,
            'intake_channel' => IntakeChannel::Manual,
            'status' => VisitStatus::Waiting,
            'online_active_key' => null,
            'checked_in_at' => now(),
            'registered_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Inherit tenant ownership from the client once the client exists, so a
     * visit can never belong to a tenant that does not own its client.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Visit $visit): void {
            if ($visit->tenant_id === null && $visit->client_id !== null) {
                $visit->tenant_id = Client::query()
                    ->whereKey($visit->client_id)
                    ->value('tenant_id');
            }

            if ($visit->facility_id === null && $visit->tenant_id !== null) {
                $visit->facility_id = Facility::query()
                    ->where('tenant_id', $visit->tenant_id)
                    ->orderBy('id')
                    ->value('id');
            }
        });
    }

    public function awaitingCheckin(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => VisitStatus::AwaitingCheckin,
            'checked_in_at' => null,
        ]);
    }

    public function online(): static
    {
        return $this->state(fn(array $attributes) => [
            'intake_channel' => IntakeChannel::Online,
            'status' => VisitStatus::AwaitingCheckin,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => VisitStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
