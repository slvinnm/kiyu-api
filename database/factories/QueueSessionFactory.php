<?php

namespace Database\Factories;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueSessionStatus;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\QueueSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QueueSession>
 */
class QueueSessionFactory extends Factory
{
    protected $model = QueueSession::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'facility_id' => null,
            'customer_id' => Customer::factory(),
            'department_id' => 1,
            'workflow_id' => 1,
            'session_number' => 'V-' . now()->format('Ymd') . '-' . str_pad((string) fake()->numberBetween(1, 999), 4, '0', STR_PAD_LEFT),
            'tracking_token' => (string) Str::uuid(),
            'priority' => Priority::Normal,
            'intake_channel' => IntakeChannel::Manual,
            'status' => QueueSessionStatus::Waiting,
            'online_active_key' => null,
            'checked_in_at' => now(),
            'registered_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Inherit tenant ownership from the client once the client exists, so a session can never belong to a tenant that does not own its customer.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (QueueSession $session): void {
            if ($session->tenant_id === null && $session->customer_id !== null) {
                $session->tenant_id = Customer::query()
                    ->whereKey($session->customer_id)
                    ->value('tenant_id');
            }

            if ($session->facility_id === null && $session->tenant_id !== null) {
                $session->facility_id = Facility::query()
                    ->where('tenant_id', $session->tenant_id)
                    ->orderBy('id')
                    ->value('id');
            }
        });
    }

    public function awaitingCheckin(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => QueueSessionStatus::AwaitingCheckin,
            'checked_in_at' => null,
        ]);
    }

    public function online(): static
    {
        return $this->state(fn(array $attributes) => [
            'intake_channel' => IntakeChannel::Online,
            'status' => QueueSessionStatus::AwaitingCheckin,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => QueueSessionStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
