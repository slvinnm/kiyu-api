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
    $intake = $this->postJson('/api/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'client_id' => $this->sampleClient->id,
    ]);

    $ticket = $this->postJson('/api/clinical/call-next', [
        'station_id' => $this->nurseStation->id,
        // A caller cannot attribute the call to someone else.
        'assigned_to' => 'somebody-else@example.com',
    ])->json('data');

    expect($ticket['assigned_to'])->toBe($this->adminUser->email);

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $ticket['id'],
        'event_type' => 'CALLED',
        'user_id' => $this->adminUser->email,
    ]);

    $this->postJson("/api/tickets/{$ticket['id']}/start", [
        'user_id' => 'somebody-else@example.com',
    ])->assertOk();

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $ticket['id'],
        'event_type' => 'STARTED',
        'user_id' => $this->adminUser->email,
    ]);

    expect($ticket['status'])->toBe(QueueTicketStatus::Called->value);
});
