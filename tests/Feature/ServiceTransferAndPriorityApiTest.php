<?php

use App\Enums\Priority;
use App\Enums\QueueTicketStatus;
use App\Enums\ServiceTransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('service transfer creates target visit in target department and initial queue ticket', function () {
    // 1. Create visit in General Department
    $intake = $this->postJson('/api/tenant/intake/online', [
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $sourceVisitId = $intake['queue_session']['id'];

    // 2. Create ServiceTransfer to Support Department (Staff-only action).
    $this->actAsStaff();

    $transferRes = $this->postJson('/api/tenant/service-transfers', [
        'source_queue_session_id' => $sourceVisitId,
        'target_department_id' => $this->supportDepartment->id,
        'priority' => Priority::Priority->value,
        'reason' => 'Customer needs support',
    ]);

    $transferRes->assertStatus(201)
        ->assertJsonPath('data.transfer.source_queue_session_id', $sourceVisitId)
        ->assertJsonPath('data.transfer.target_department_id', $this->supportDepartment->id)
        ->assertJsonPath('data.transfer.status', ServiceTransferStatus::Accepted->value)
        ->assertJsonPath('data.target_session.department_id', $this->supportDepartment->id)
        ->assertJsonPath('data.target_session.customer_id', $this->sampleCustomer->id)
        ->assertJsonPath('data.target_ticket.status', QueueTicketStatus::Created->value);

    // Verify rejection of service transfer to same department
    $sameDeptRes = $this->postJson('/api/tenant/service-transfers', [
        'source_queue_session_id' => $sourceVisitId,
        'target_department_id' => $this->generalDepartment->id,
    ]);

    $sameDeptRes->assertStatus(422);
});

test('updating session priority propagates to active tickets only and preserves historical tickets', function () {
    // Create walk-in visit
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
        'priority' => Priority::Normal->value,
    ])->json('data');

    $sessionId = $intake['queue_session']['id'];

    // Call registration ticket
    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);

    // Complete registration -> Registration ticket becomes COMPLETED, Nurse ticket is created
    $this->postJson('/api/tenant/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'customer_id' => $this->sampleCustomer->id,
    ]);

    // Active ticket is now the Nurse ticket (CREATED, priority = 1)
    // Registration ticket is COMPLETED (priority = 1)

    // Elevate Visit Priority to EMERGENCY (3)
    $priorityRes = $this->patchJson("/api/tenant/queue-sessions/{$sessionId}/priority", [
        'priority' => Priority::Emergency->value,
    ]);

    $priorityRes->assertStatus(200)
        ->assertJsonPath('data.priority', Priority::Emergency->value);

    // Active Nurse ticket must have updated to EMERGENCY (3)
    $this->assertDatabaseHas('queue_tickets', [
        'queue_session_id' => $sessionId,
        'station_id' => $this->serviceStation1->id,
        'priority' => Priority::Emergency->value,
        'status' => QueueTicketStatus::Created->value,
    ]);

    // Historical Registration ticket must STILL have priority NORMAL (1)
    $this->assertDatabaseHas('queue_tickets', [
        'queue_session_id' => $sessionId,
        'station_id' => $this->registrationStation->id,
        'priority' => Priority::Normal->value,
        'status' => QueueTicketStatus::Completed->value,
    ]);

    // Verify AuditLog was recorded
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'QUEUE_SESSION_PRIORITY_CHANGED',
        'entity_id' => (string) $sessionId,
        'user_id' => $this->adminUser->id,
    ]);
});

test('public queue display exposes operational numbers without any customer PII', function () {
    // 1. Create walk-in
    $intake = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    // 2. Call ticket
    $this->postJson('/api/tenant/reception/call-next', ['department_id' => $this->generalDepartment->id]);

    // 3. Query public queue
    $publicRes = $this->getJson('/api/tenant/public/queue');

    $publicRes->assertStatus(200);

    $json = $publicRes->getContent();

    // Verify NO customer PII exists in the public queue response
    expect($json)->not->toContain($this->sampleCustomer->name)
        ->not->toContain($this->sampleCustomer->phone);

    $regStationDisplay = collect($publicRes->json('data'))->firstWhere('station_id', $this->registrationStation->id);
    expect($regStationDisplay)->not->toBeNull();
    expect($regStationDisplay['current_calling']['queue_number'])->toBe('R001');
    expect($regStationDisplay['current_calling']['status'])->toBe(QueueTicketStatus::Called->value);
});
