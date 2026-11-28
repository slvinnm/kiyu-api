<?php

use App\Enums\QueueSessionStatus;
use App\Enums\QueueTicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('queue call-next claims ticket for queue station', function () {
    // 1. Create walk-in and complete registration to create nurse ticket
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', [
        'department_id' => $this->generalDepartment->id,
    ]);

    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    // 2. Nurse calls next at nurse station
    $callRes = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
    ]);

    // The actor is the authenticated user, not a body value.
    $callRes->assertStatus(200)
        ->assertJsonPath('data.status', QueueTicketStatus::Called->value)
        ->assertJsonPath('data.station_id', $this->serviceStation1->id)
        ->assertJsonPath('data.assigned_to_user_id', $this->adminUser->id);

    // Verify QueueEvent CALLED
    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $callRes->json('data.id'),
        'event_type' => 'CALLED',
        'to_status' => 'CALLED',
    ]);
});

test('starting queue service transitions ticket to IN_PROGRESS and transitions Visit from WAITING to IN_PROGRESS', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $sessionId = $intake['queue_session']['id'];

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    // Nurse calls next
    $nurseTicket = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
    ])->json('data');

    // Confirm visit is still WAITING before queue start
    $this->assertDatabaseHas('queue_sessions', [
        'id' => $sessionId,
        'status' => QueueSessionStatus::Waiting->value,
    ]);

    // Nurse starts service
    $startRes = $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/start");

    $startRes->assertStatus(200)
        ->assertJsonPath('data.status', QueueTicketStatus::InProgress->value);

    // CRITICAL INVARIANT: Visit status must now be IN_PROGRESS!
    $this->assertDatabaseHas('queue_sessions', [
        'id' => $sessionId,
        'status' => QueueSessionStatus::InProgress->value,
    ]);

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $nurseTicket['id'],
        'event_type' => 'STARTED',
        'to_status' => 'IN_PROGRESS',
    ]);
});

test('holding a ticket sets status ON_HOLD and excludes it from call-next, then resume sets back to CALLED', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    $nurseTicket = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
    ])->json('data');

    // Hold the ticket
    $holdRes = $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/hold", [
        'notes' => 'Client waiting for lab results',
    ]);

    $holdRes->assertStatus(200)
        ->assertJsonPath('data.status', QueueTicketStatus::OnHold->value);

    // Call-next should NOT find the ON_HOLD ticket
    $secondCall = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
    ]);

    $secondCall->assertStatus(200)
        ->assertJsonPath('data', null);

    // Resume the ticket
    $resumeRes = $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/resume");

    $resumeRes->assertStatus(200)
        ->assertJsonPath('data.status', QueueTicketStatus::Called->value);
});

test('queue completion advances workflow to next step and finally completes visit', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $sessionId = $intake['queue_session']['id'];

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    // 1. Nurse step (step 2)
    $nurseTicket = $this->postJson('/api/tenant/queue/call-next', ['station_id' => $this->serviceStation1->id])->json('data');
    $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/start")->assertStatus(200);
    $completeNurse = $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/complete")->assertStatus(200);

    // Next step is the follow-up counter (step 3).
    $docTicketId = $completeNurse->json('data.next_ticket.id');
    expect($completeNurse->json('data.next_ticket.station_id'))->toBe($this->serviceStation2->id);

    // 2. Follow-up step (step 3)
    $this->postJson('/api/tenant/queue/call-next', ['station_id' => $this->serviceStation2->id])->assertStatus(200);
    $this->postJson("/api/tenant/tickets/{$docTicketId}/start")->assertStatus(200);
    $completeDoc = $this->postJson("/api/tenant/tickets/{$docTicketId}/complete")->assertStatus(200);

    // Next step is the cashier (step 4).
    $pharmTicketId = $completeDoc->json('data.next_ticket.id');
    expect($completeDoc->json('data.next_ticket.station_id'))->toBe($this->cashierStation->id);

    // 3. Cashier step (step 4 - final step)
    $this->postJson('/api/tenant/queue/call-next', ['station_id' => $this->cashierStation->id])->assertStatus(200);
    $this->postJson("/api/tenant/tickets/{$pharmTicketId}/start")->assertStatus(200);
    $completePharm = $this->postJson("/api/tenant/tickets/{$pharmTicketId}/complete")->assertStatus(200);

    // No next step -> Visit COMPLETED
    expect($completePharm->json('data.next_ticket'))->toBeNull();
    expect($completePharm->json('data.queue_session.status'))->toBe(QueueSessionStatus::Completed->value);

    $this->assertDatabaseHas('queue_sessions', [
        'id' => $sessionId,
        'status' => QueueSessionStatus::Completed->value,
    ]);
});

test('ticket transfer moves client to another compatible station in the same department and step', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $reg = $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ])->json('data');

    // Complete Nurse step to reach Doctor step
    $nurseTicket = $this->postJson('/api/tenant/queue/call-next', ['station_id' => $this->serviceStation1->id])->json('data');
    $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/start");
    $docTicket = $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/complete")->json('data.next_ticket');

    // Call the follow-up ticket at the second service counter.
    $this->postJson('/api/tenant/queue/call-next', ['station_id' => $this->serviceStation2->id]);

    // Transfer between two stations of the same department and type.
    // Ticket reassignment is Staff-only (PRD §14.6).
    $this->actAsStaff();

    $transferRes = $this->postJson("/api/tenant/tickets/{$docTicket['id']}/transfer", [
        'target_station_id' => $this->serviceStation1->id,
        'notes' => 'Transferring to the other service counter',
    ]);

    $transferRes->assertStatus(200)
        ->assertJsonPath('data.old_ticket.status', QueueTicketStatus::Transferred->value)
        ->assertJsonPath('data.old_ticket.transferred_to_station_id', $this->serviceStation1->id)
        ->assertJsonPath('data.new_ticket.status', QueueTicketStatus::Created->value)
        ->assertJsonPath('data.new_ticket.station_id', $this->serviceStation1->id)
        ->assertJsonPath('data.new_ticket.transferred_from_ticket_id', $docTicket['id']);

    // Attempting to transfer to an incompatible station (e.g. Pharmacy) must be rejected
    $incompatibleRes = $this->postJson("/api/tenant/tickets/{$transferRes->json('data.new_ticket.id')}/transfer", [
        'target_station_id' => $this->cashierStation->id,
    ]);

    // Needs to be CALLED or IN_PROGRESS before transferring
    $incompatibleRes->assertStatus(422);
});

test('queue audit entries are scoped to the acting tenant', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    $this->postJson('/api/tenant/queue/call-next', ['station_id' => $this->serviceStation1->id]);

    // Every queue-side audit row must carry the tenant, otherwise the tenant
    // audit log endpoint can never show them.
    $this->assertDatabaseMissing('audit_logs', [
        'tenant_id' => null,
    ]);

    $response = $this->actingAs(
        User::factory()->create([
            'role' => 'tenant_admin',
            'tenant_id' => $this->tenant->id,
        ]),
        'sanctum',
    )->asContext('app')->getJson('/api/app/audit-logs');

    $response->assertOk();

    $actions = collect($response->json('data.data'))->pluck('action');

    expect($actions)->toContain('RECEPTION_CALL_NEXT')
        ->and($actions)->toContain('QUEUE_CALL_NEXT');
});

test('a step cannot be skipped unless its step is skippable', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    $nurseTicket = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
    ])->json('data');

    // The seeded nurse step is mandatory.
    $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/skip")
        ->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_STATE');

    $this->serviceStep1->update(['can_skip' => true]);

    $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/skip")->assertOk();
});

test('skipping a ticket advances the visit to the next step', function () {
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    $nurseTicket = $this->postJson('/api/tenant/queue/call-next', [
        'station_id' => $this->serviceStation1->id,
    ])->json('data');

    $this->serviceStep1->update(['can_skip' => true]);

    $this->postJson("/api/tenant/tickets/{$nurseTicket['id']}/skip")->assertOk();

    // The next workflow step's station now has a ticket for the session.
    $this->assertDatabaseHas('queue_tickets', [
        'queue_session_id' => $intake['queue_session']['id'],
        'station_id' => $this->serviceStation2->id,
        'status' => QueueTicketStatus::Created->value,
    ]);
});

test('marking a called ticket no-show records the event', function () {
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
    ])->json('data');

    $this->postJson("/api/tenant/tickets/{$ticket['id']}/no-show")
        ->assertOk()
        ->assertJsonPath('data.status', QueueTicketStatus::NoShow->value);

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $ticket['id'],
        'event_type' => 'NO_SHOW',
    ]);
});

test('cancelling an active ticket records the event', function () {
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
    ])->json('data');

    $this->postJson("/api/tenant/tickets/{$ticket['id']}/cancel", ['notes' => 'Client left'])
        ->assertOk()
        ->assertJsonPath('data.status', QueueTicketStatus::Cancelled->value);

    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $ticket['id'],
        'event_type' => 'CANCELLED',
    ]);
});

test('a historical ticket cannot be cancelled again', function () {
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
    ])->json('data');

    $this->postJson("/api/tenant/tickets/{$ticket['id']}/cancel")->assertOk();
    $this->postJson("/api/tenant/tickets/{$ticket['id']}/cancel")->assertStatus(422);
});
