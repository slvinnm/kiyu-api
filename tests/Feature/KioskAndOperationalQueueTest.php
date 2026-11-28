<?php

namespace Tests\Feature;

use App\Enums\Priority;
use App\Enums\QueueSessionStatus;
use App\Enums\QueueTicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;
use Tests\TestCase;

class KioskAndOperationalQueueTest extends TestCase
{
    use RefreshDatabase;
    use SetupKiyuHospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHospital();
    }

    public function test_can_list_active_departments_for_kiosk(): void
    {
        $response = $this->getJson('/api/tenant/public/departments/active');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'is_active']]]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_lookup_online_booking_preview_at_kiosk(): void
    {
        $onlineBooking = $this->postJson('/api/tenant/intake/online', [
            'customer_id' => $this->sampleCustomer->id,
            'department_id' => $this->generalDepartment->id,
            'priority' => Priority::Normal->value,
        ])->json('data');

        $activeKey = $onlineBooking['queue_session']['online_active_key'];

        $response = $this->getJson("/api/tenant/intake/online-lookup/{$activeKey}");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_eligible_checkin', true)
            ->assertJsonPath('data.status', QueueSessionStatus::AwaitingCheckin->value)
            ->assertJsonPath('data.department_name', 'General Service');

        // Verify client name is masked for privacy
        $this->assertStringContainsString('*', $response->json('data.customer_preview.masked_name'));
    }

    public function test_can_recall_reception_ticket(): void
    {
        $intake = $this->postJson('/api/tenant/intake/kiosk', [
            'department_id' => $this->generalDepartment->id,
        ])->json('data');

        $callNext = $this->postJson('/api/tenant/reception/call-next', [
            'department_id' => $this->generalDepartment->id,
            'assigned_to_user_id' => 'receptionist_1',
        ])->json('data');

        $ticketId = $callNext['id'];

        $response = $this->postJson("/api/tenant/reception/{$ticketId}/recall", [
            'assigned_to_user_id' => 'receptionist_1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', QueueTicketStatus::Called->value);

        $this->assertDatabaseHas('queue_events', [
            'queue_ticket_id' => $ticketId,
            'event_type' => 'RECALLED',
        ]);
    }

    public function test_can_skip_and_no_show_reception_ticket(): void
    {
        $intake = $this->postJson('/api/tenant/intake/kiosk', [
            'department_id' => $this->generalDepartment->id,
        ])->json('data');

        $callNext = $this->postJson('/api/tenant/reception/call-next', [
            'department_id' => $this->generalDepartment->id,
            'assigned_to_user_id' => 'receptionist_1',
        ])->json('data');

        $ticketId = $callNext['id'];

        $response = $this->postJson("/api/tenant/reception/{$ticketId}/skip", [
            'reason' => 'Client went to the restroom',
            'user_id' => 'receptionist_1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', QueueTicketStatus::Skipped->value);

        $this->assertDatabaseHas('queue_tickets', [
            'id' => $ticketId,
            'status' => QueueTicketStatus::Skipped->value,
            'notes' => 'Client went to the restroom',
        ]);
    }

    public function test_can_recall_queue_ticket(): void
    {
        // Setup a full walkin until queue station
        $intake = $this->postJson('/api/tenant/intake/kiosk', [
            'department_id' => $this->generalDepartment->id,
        ])->json('data');

        $callNext = $this->postJson('/api/tenant/reception/call-next', [
            'department_id' => $this->generalDepartment->id,
        ])->json('data');

        $reg = $this->postJson('/api/tenant/reception/complete-registration', [
            'acquisition_id' => $intake['acquisition']['id'],
            'customer_id' => $this->sampleCustomer->id,
        ])->json('data');

        $queueTicket = $reg['next_ticket'];

        $called = $this->postJson('/api/tenant/queue/call-next', [
            'station_id' => $queueTicket['station_id'],
            'assigned_to_user_id' => 'nurse_1',
        ])->json('data');

        $response = $this->postJson("/api/tenant/tickets/{$called['id']}/recall", [
            'user_id' => 'nurse_1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', QueueTicketStatus::Called->value);

        $this->assertDatabaseHas('queue_events', [
            'queue_ticket_id' => $called['id'],
            'event_type' => 'RECALLED',
        ]);
    }

    public function test_registration_creates_a_customer_without_identity_documents(): void
    {
        $intake = $this->postJson('/api/tenant/intake/kiosk', [
            'department_id' => $this->generalDepartment->id,
        ])->json('data');

        $response = $this->postJson('/api/tenant/reception/complete-registration', [
            'acquisition_id' => $intake['acquisition']['id'],
            'customer_data' => [
                'name' => 'Budi Duplikat',
                'phone' => '081200000000',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.queue_session.customer.name', 'Budi Duplikat');
    }
}
