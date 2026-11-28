<?php

namespace Tests\Feature;

use App\Enums\QueueTicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('the actor on a queue transition comes from the authenticated user', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    $ticket = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
        // A caller cannot attribute the call to someone else.
        'assigned_to_user_id' => 999999,
    ])->json('data');

    expect($ticket['assigned_to_user_id'])->toBe($this->adminUser->id);

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $ticket['id'],
        'event_type' => 'CALLED',
        'user_id' => $this->adminUser->id,
    ]);

    $this->postJson("/api/tenant/tickets/{$ticket['id']}/start", [
        'user_id' => 'somebody-else@example.com',
    ])->assertOk();

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $ticket['id'],
        'event_type' => 'STARTED',
        'user_id' => $this->adminUser->id,
    ]);

    expect($ticket['status'])->toBe(QueueTicketStatus::Called->value);
});
