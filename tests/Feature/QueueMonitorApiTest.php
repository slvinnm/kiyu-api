<?php

use App\Enums\QueueTicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('queue monitor requires authentication', function () {
    // Drop the Sanctum user the fixture installed; the kiosk headers remain.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/queue/monitor')->assertStatus(401);
});

test('queue monitor lists active stations with the public read model', function () {
    $response = $this->getJson('/api/queue/monitor');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'station_id',
                'station_name',
                'station_code',
                'station_type',
                'department_id',
                'department_name',
                'department_code',
                'current_calling',
                'waiting_count',
                'upcoming_queue_numbers',
            ]],
        ]);

    $stationIds = collect($response->json('data'))->pluck('station_id');

    expect($stationIds)->toContain($this->nurseStation->id);
});

test('queue monitor reports the called ticket and waiting count', function () {
    $intake = $this->postJson('/api/intake/kiosk', [
        'department_id' => $this->generalDepartment->id,
    ])->json('data');

    $this->postJson('/api/reception/call-next', [
        'department_id' => $this->generalDepartment->id,
    ]);

    $this->postJson('/api/reception/complete-registration', [
        'acquisition_id' => $intake['acquisition']['id'],
        'client_id' => $this->sampleClient->id,
    ]);

    $this->postJson('/api/clinical/call-next', [
        'station_id' => $this->nurseStation->id,
        'assigned_to' => 'nurse-bob',
    ])->assertOk();

    $station = collect($this->getJson('/api/queue/monitor')->json('data'))
        ->firstWhere('station_id', $this->nurseStation->id);

    expect($station)->not->toBeNull()
        ->and($station['current_calling']['status'])->toBe(QueueTicketStatus::Called->value)
        ->and($station['current_calling']['queue_number'])->not->toBeNull();
});

test('queue monitor filters by department and station', function () {
    $byDepartment = $this->getJson('/api/queue/monitor?department_id='.$this->generalDepartment->id)
        ->assertOk()
        ->json('data');

    expect($byDepartment)->not->toBeEmpty();
    expect(collect($byDepartment)->pluck('department_id')->unique()->all())
        ->toBe([$this->generalDepartment->id]);

    $byStation = $this->getJson('/api/queue/monitor?station_id='.$this->nurseStation->id)
        ->assertOk()
        ->json('data');

    expect(collect($byStation)->pluck('station_id')->all())->toBe([$this->nurseStation->id]);
});

test('queue monitor does not leak another tenant stations', function () {
    $otherTenant = \App\Models\Tenant::factory()->create();
    $otherFacility = \App\Models\Facility::factory()->create([
        'tenant_id' => $otherTenant->id,
        'is_active' => true,
    ]);
    $otherDepartment = \App\Models\Department::create([
        'facility_id' => $otherFacility->id,
        'code' => 'OTH',
        'name' => 'Other Department',
        'is_active' => true,
    ]);
    $otherStation = \App\Models\Station::create([
        'department_id' => $otherDepartment->id,
        'code' => 'OTH-1',
        'name' => 'Other Station',
        'type' => \App\Enums\StationType::Registration,
        'is_active' => true,
    ]);

    $stationIds = collect($this->getJson('/api/queue/monitor')->assertOk()->json('data'))
        ->pluck('station_id');

    expect($stationIds)->not->toContain($otherStation->id);
});
