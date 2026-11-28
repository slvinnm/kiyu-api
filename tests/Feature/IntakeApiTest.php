<?php

use App\Enums\IntakeChannel;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueSessionStatus;
use App\Enums\QueueTicketStatus;
use App\Models\QueueAcquisition;
use App\Models\QueueSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('kiosk walk-in creates client-less visit, acquisition and registration queue ticket', function () {
    $response = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.queue_session.customer_id', null)
        ->assertJsonPath('data.queue_session.status', QueueSessionStatus::Waiting->value)
        ->assertJsonPath('data.queue_session.intake_channel', IntakeChannel::Kiosk->value)
        ->assertJsonPath('data.acquisition.status', QueueAcquisitionStatus::Acquired->value)
        ->assertJsonPath('data.ticket.status', QueueTicketStatus::Created->value)
        ->assertJsonPath('data.ticket.station_id', $this->registrationStation->id)
        ->assertJsonPath('data.ticket.queue_number', 'R001');

    $this->assertDatabaseHas('queue_sessions', [
        'customer_id' => null,
        'department_id' => $this->generalDepartment->id,
        'status' => QueueSessionStatus::Waiting->value,
    ]);

    $this->assertDatabaseHas('queue_acquisitions', [
        'channel' => IntakeChannel::Kiosk->value,
        'status' => QueueAcquisitionStatus::Acquired->value,
    ]);

    $this->assertDatabaseHas('queue_tickets', [
        'queue_number' => 'R001',
        'status' => QueueTicketStatus::Created->value,
    ]);
});

test('manual intake creates client-less visit and registration queue ticket', function () {
    $response = $this->postJson('/api/tenant/intake/manual', [
        'department_id' => $this->generalDepartment->id,
        'priority' => 2,
        'registered_by_user_id' => 'receptionist-1',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.queue_session.customer_id', null)
        ->assertJsonPath('data.queue_session.status', QueueSessionStatus::Waiting->value)
        ->assertJsonPath('data.queue_session.priority', 2)
        ->assertJsonPath('data.ticket.priority', 2)
        ->assertJsonPath('data.ticket.status', QueueTicketStatus::Created->value);
});

test('online booking links existing client, sets status AWAITING_CHECKIN, and creates NO queue ticket yet', function () {
    $response = $this->postJson('/api/tenant/intake/online', [
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'priority' => 1,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.queue_session.customer_id', $this->sampleCustomer->id)
        ->assertJsonPath('data.queue_session.status', QueueSessionStatus::AwaitingCheckin->value)
        ->assertJsonPath('data.ticket', null);

    $sessionId = $response->json('data.queue_session.id');

    // Confirm NO tickets exist for this visit yet
    $this->assertDatabaseMissing('queue_tickets', [
        'queue_session_id' => $sessionId,
    ]);
});

test('online client self check-in via kiosk transitions visit to WAITING and creates registration queue ticket', function () {
    // 1. Create online booking
    $bookingResponse = $this->postJson('/api/tenant/intake/online', [
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
    ]);

    $activeKey = $bookingResponse->json('data.queue_session.online_active_key');
    $sessionId = $bookingResponse->json('data.queue_session.id');

    // 2. Perform online check-in using the active key
    $checkinResponse = $this->postJson('/api/tenant/intake/online-checkin', [
        'booking_identifier' => $activeKey,
    ]);

    $checkinResponse->assertStatus(200)
        ->assertJsonPath('data.queue_session.status', QueueSessionStatus::Waiting->value)
        ->assertJsonPath('data.ticket.status', QueueTicketStatus::Created->value)
        ->assertJsonPath('data.ticket.station_id', $this->registrationStation->id);

    $this->assertDatabaseHas('queue_sessions', [
        'id' => $sessionId,
        'status' => QueueSessionStatus::Waiting->value,
    ]);

    $this->assertDatabaseHas('queue_tickets', [
        'queue_session_id' => $sessionId,
        'station_id' => $this->registrationStation->id,
        'status' => QueueTicketStatus::Created->value,
    ]);
});

test('online check-in rejects already checked-in visit', function () {
    $bookingResponse = $this->postJson('/api/tenant/intake/online', [
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
    ]);

    $activeKey = $bookingResponse->json('data.queue_session.online_active_key');

    // First check-in
    $this->postJson('/api/tenant/intake/online-checkin', [
        'booking_identifier' => $activeKey,
    ])->assertStatus(200);

    // Second check-in must fail
    $secondCheckin = $this->postJson('/api/tenant/intake/online-checkin', [
        'booking_identifier' => $activeKey,
    ]);

    $secondCheckin->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_STATE');
});

test('idempotency key returns same result for identical payload and 409 for conflict', function () {
    $key = 'idemp-test-12345';

    // 1st call
    $res1 = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
        'idempotency_key' => $key,
    ])->assertStatus(201);

    $sessionId1 = $res1->json('data.queue_session.id');

    // 2nd call with same parameters
    $res2 = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
        'idempotency_key' => $key,
    ])->assertStatus(201);

    expect($res2->json('data.queue_session.id'))->toBe($sessionId1);

    // 3rd call with different parameters but same idempotency key -> conflict 409
    $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->supportDepartment->id,
        'idempotency_key' => $key,
    ])->assertStatus(409);
});

test('public visit tracking is rate limited', function () {
    $token = (string) Str::uuid();

    for ($i = 0; $i < 60; $i++) {
        $this->getJson("/api/tenant/public/tracking/{$token}")->assertStatus(422);
    }

    $this->getJson("/api/tenant/public/tracking/{$token}")->assertStatus(429);
});

test('kiosk intake ignores a client-supplied priority', function () {
    $response = $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
        'priority' => 3,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.queue_session.priority', 1)
        ->assertJsonPath('data.ticket.priority', 1);
});

test('online lookup only resolves the booking key, not a visit id', function () {
    $booking = $this->postJson('/api/tenant/intake/online', [
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $activeKey = $booking['queue_session']['online_active_key'];

    $this->getJson("/api/tenant/intake/online-lookup/{$activeKey}")
        ->assertOk()
        ->assertJsonMissingPath('data.booking_identifier')
        ->assertJsonMissingPath('data.client_preview.mrn')
        ->assertJsonMissingPath('data.priority');

    // A sequential visit id or the visit number must not resolve.
    $this->getJson("/api/tenant/intake/online-lookup/{$booking['queue_session']['id']}")
        ->assertStatus(422);

    $this->getJson("/api/tenant/intake/online-lookup/{$booking['queue_session']['session_number']}")
        ->assertStatus(422);
});

test('the database rejects a duplicate idempotency key for a tenant', function () {
    $this->postJson('/api/tenant/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
        'idempotency_key' => 'db-unique-1',
    ])->assertCreated();

    expect(fn() => QueueAcquisition::create([
        'queue_session_id' => QueueSession::query()->latest('id')->value('id'),
        'tenant_id' => $this->tenant->id,
        'department_id' => $this->generalDepartment->id,
        'channel' => IntakeChannel::Kiosk,
        'status' => QueueAcquisitionStatus::Acquired,
        'idempotency_key' => 'db-unique-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
