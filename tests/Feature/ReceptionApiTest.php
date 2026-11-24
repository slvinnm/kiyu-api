<?php

use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('reception call-next automatically resolves registration station and calls highest priority first', function () {
    // 1. Create NORMAL intake (seq 1)
    $this->postJson('/api/intake/manual', [
        'department_id' => $this->generalDepartment->id,
        'priority' => Priority::Normal->value,
    ])->assertStatus(201);

    // 2. Create EMERGENCY intake (seq 2). Priority is staff-set: the kiosk
    // endpoint always creates NORMAL tickets.
    $emergencyRes = $this->postJson('/api/intake/manual', [
        'department_id' => $this->generalDepartment->id,
        'priority' => Priority::Emergency->value,
    ])->assertStatus(201);

    $emergencyTicketId = $emergencyRes->json('data.ticket.id');

    // 3. Receptionist calls next
    $callResponse = $this->postJson('/api/reception/call-next', [
        'department_id' => $this->generalDepartment->id,
    ]);

    // The actor is the authenticated user, not a body value.
    $callResponse->assertStatus(200)
        ->assertJsonPath('data.id', $emergencyTicketId)
        ->assertJsonPath('data.status', QueueTicketStatus::Called->value)
        ->assertJsonPath('data.priority', Priority::Emergency->value)
        ->assertJsonPath('data.assigned_to', $this->adminUser->email);

    // Verify QueueEvent was created
    $this->assertDatabaseHas('queue_events', [
        'queue_ticket_id' => $emergencyTicketId,
        'event_type' => 'CALLED',
        'from_status' => 'CREATED',
        'to_status' => 'CALLED',
        'user_id' => $this->adminUser->email,
    ]);
});

test('reception complete-registration links client, completes ticket, creates next clinical ticket, and keeps visit WAITING', function () {
    // 1. Create kiosk intake
    $intake = $this->postJson('/api/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $acqId = $intake['acquisition']['id'];
    $visitId = $intake['visit']['id'];

    // 2. Call ticket
    $this->postJson('/api/reception/call-next', [
        'department_id' => $this->generalDepartment->id,
    ])->assertStatus(200);

    // 3. Complete registration linking existing client
    $completeRes = $this->postJson('/api/reception/complete-registration', [
        'acquisition_id' => $acqId,
        'client_id' => $this->sampleClient->id,
        'registered_by' => $this->adminUser->email,
    ]);

    $completeRes->assertStatus(200)
        ->assertJsonPath('data.acquisition.status', QueueAcquisitionStatus::Registered->value)
        ->assertJsonPath('data.registration_ticket.status', QueueTicketStatus::Completed->value)
        ->assertJsonPath('data.visit.client_id', $this->sampleClient->id)
        // CRITICAL INVARIANT: Visit status must remain WAITING!
        ->assertJsonPath('data.visit.status', VisitStatus::Waiting->value)
        ->assertJsonPath('data.next_ticket.status', QueueTicketStatus::Created->value)
        ->assertJsonPath('data.next_ticket.station_id', $this->nurseStation->id);

    // Verify in database
    $this->assertDatabaseHas('visits', [
        'id' => $visitId,
        'status' => VisitStatus::Waiting->value,
        'client_id' => $this->sampleClient->id,
    ]);

    $this->assertDatabaseHas('queue_acquisitions', [
        'id' => $acqId,
        'status' => QueueAcquisitionStatus::Registered->value,
    ]);

    $this->assertDatabaseHas('queue_tickets', [
        'visit_id' => $visitId,
        'station_id' => $this->nurseStation->id,
        'status' => QueueTicketStatus::Created->value,
    ]);
});

test('reception complete-registration can create a new client on the fly', function () {
    $intake = $this->postJson('/api/intake/manual', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $acqId = $intake['acquisition']['id'];

    $completeRes = $this->postJson('/api/reception/complete-registration', [
        'acquisition_id' => $acqId,
        'client_data' => [
            'name' => 'Siti Nurhaliza',
            'nik' => '3201015505950002',
            'phone' => '08987654321',
        ],
    ]);

    $completeRes->assertStatus(200)
        ->assertJsonPath('data.visit.client.name', 'Siti Nurhaliza');

    $this->assertDatabaseHas('clients', [
        'name' => 'Siti Nurhaliza',
        'nik' => '3201015505950002',
    ]);
});

test('reception double-completion is prevented and returns conflict 409', function () {
    $intake = $this->postJson('/api/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $acqId = $intake['acquisition']['id'];

    // Call ticket
    $this->postJson('/api/reception/call-next', [
        'department_id' => $this->generalDepartment->id,
    ])->assertStatus(200);

    // 1st completion succeeds
    $this->postJson('/api/reception/complete-registration', [
        'acquisition_id' => $acqId,
        'client_id' => $this->sampleClient->id,
    ])->assertStatus(200);

    // 2nd completion must fail with conflict (409)
    $secondRes = $this->postJson('/api/reception/complete-registration', [
        'acquisition_id' => $acqId,
        'client_id' => $this->sampleClient->id,
    ]);

    $secondRes->assertStatus(409)
        ->assertJsonPath('error_type', 'BUSINESS_CONFLICT');
});

test('reception queue view displays current calling and waiting tickets for the department', function () {
    // 1. Create walk-in 1
    $res1 = $this->postJson('/api/intake/manual', [
        'department_id' => $this->generalDepartment->id,
        'priority' => Priority::Normal->value,
    ])->json('data');

    // 2. Create walk-in 2
    $res2 = $this->postJson('/api/intake/manual', [
        'department_id' => $this->generalDepartment->id,
        'priority' => Priority::Priority->value,
    ])->json('data');

    // View queue before call
    $viewRes = $this->getJson("/api/reception/queue?department_id={$this->generalDepartment->id}");
    $viewRes->assertStatus(200)
        ->assertJsonPath('message', 'Reception queue retrieved.')
        ->assertJsonPath('data.waiting_count', 2)
        ->assertJsonPath('data.current_calling', null)
        // High priority should be first in waiting list
        ->assertJsonPath('data.waiting.0.priority', Priority::Priority->value);

    // Call first
    $this->postJson('/api/reception/call-next', [
        'department_id' => $this->generalDepartment->id,
    ])->assertStatus(200);

    // View queue again
    $viewAfterCall = $this->getJson("/api/reception/queue?department_id={$this->generalDepartment->id}");
    $viewAfterCall->assertStatus(200)
        ->assertJsonPath('data.waiting_count', 1)
        ->assertJsonPath('data.current_calling.queue_number', $res2['ticket']['queue_number']);
});
