<?php

use App\Enums\IntakeChannel;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueTicketStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Department;
use App\Models\QueueAcquisition;
use App\Models\QueueSession;
use App\Models\QueueTicket;
use App\Models\ServiceTransfer;
use App\Models\Station;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('master data table endpoints paginate by default', function () {
    Department::create(['facility_id' => $this->facility->id, 'code' => 'DEPT_ANAK', 'name' => 'Child Service', 'is_active' => true]);
    Department::create(['facility_id' => $this->facility->id, 'code' => 'DEPT_SARAF', 'name' => 'Neuro Service', 'is_active' => true]);
    Department::create(['facility_id' => $this->facility->id, 'code' => 'DEPT_JANTUNG', 'name' => 'Heart Service', 'is_active' => true]);

    $this->getJson('/api/departments?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.last_page', 3)
        ->assertJsonCount(2, 'data.data');

    $stationCount = Station::query()->count();

    $this->getJson('/api/stations?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('data.total', $stationCount)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.last_page', (int) ceil($stationCount / 2))
        ->assertJsonCount(2, 'data.data');

    Workflow::create([
        'department_id' => $this->generalDepartment->id,
        'name' => 'Alur Kontrol',
        'is_active' => true,
    ]);

    $this->getJson('/api/workflows?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.last_page', 2)
        ->assertJsonCount(2, 'data.data');
});

test('master data options endpoints return the full lightweight list', function () {
    $this->getJson('/api/departments/options')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'is_active', 'display_in_kiosk']]]);

    $this->getJson('/api/stations/options')
        ->assertOk()
        ->assertJsonCount(Station::query()->count(), 'data')
        ->assertJsonStructure(['data' => [['id', 'department_id', 'code', 'name', 'type', 'is_active']]]);

    $this->getJson("/api/stations/options?department_id={$this->supportDepartment->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/stations/options?type=CASHIER')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'CSH-01');
});

test('statistics endpoints return aggregate counts', function () {
    Customer::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-STATS-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);

    $this->getJson('/api/workflows/statistics')
        ->assertOk()
        ->assertJsonPath('data.total', 2);

    $this->getJson('/api/customers/statistics')
        ->assertOk()
        ->assertJsonPath('data.total', 4);

    $this->getJson('/api/queue-sessions/statistics')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/queue-sessions/statistics?status=CANCELLED')
        ->assertOk()
        ->assertJsonPath('data.total', 0);

    QueueTicket::factory()->create([
        'queue_session_id' => QueueSession::query()->firstOrFail()->id,
        'workflow_step_id' => $this->regStep->id,
        'station_id' => $this->registrationStation->id,
        'status' => QueueTicketStatus::Created,
    ]);

    $this->getJson('/api/tickets/statistics?status=CREATED')
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

test('per page is clamped between one and one hundred', function () {
    $this->getJson('/api/departments?per_page=999')
        ->assertOk()
        ->assertJsonPath('data.per_page', 100);

    $this->getJson('/api/departments?per_page=0')
        ->assertOk()
        ->assertJsonPath('data.per_page', 1);

    $this->getJson('/api/audit-logs?per_page=999')
        ->assertOk()
        ->assertJsonPath('data.per_page', 100);
});

test('department search matches code and name', function () {
    $this->getJson('/api/departments?search=Support')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'SUP');

    $this->getJson('/api/departments?search=General')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'GEN');

    $this->getJson('/api/departments?search=%20%20')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('station search matches own columns and related department name', function () {
    $this->getJson('/api/stations?search=Cashier')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'CSH-01');

    $this->getJson('/api/stations?search=Support')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('workflow search matches name and related department name', function () {
    $this->getJson('/api/workflows?search=Support')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.name', 'Support Flow');

    $this->getJson('/api/workflows?search=General')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.name', 'General Flow');
});

test('visit search matches visit number and client identity', function () {
    $otherCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Siti Aminah', 'phone' => '081200000002']);

    $budiVisit = QueueSession::factory()->create([
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-TEST-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);

    QueueSession::factory()->create([
        'customer_id' => $otherCustomer->id,
        'department_id' => $this->supportDepartment->id,
        'workflow_id' => $this->supportWorkflow->id,
        'session_number' => 'V-TEST-0002',
        'tracking_token' => (string) Str::uuid(),
    ]);

    $this->getJson('/api/queue-sessions?search=V-TEST-0001')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $budiVisit->id);

    $this->getJson('/api/queue-sessions?search=Budi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $budiVisit->id);

    $this->getJson('/api/queue-sessions?search=081200000002')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.customer_id', $otherCustomer->id);
});

test('ticket search matches queue number and client name', function () {
    $visit = QueueSession::factory()->create([
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-TEST-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);

    QueueTicket::factory()->create([
        'queue_session_id' => $visit->id,
        'workflow_step_id' => $this->regStep->id,
        'station_id' => $this->registrationStation->id,
        'queue_number' => 'R900',
        'status' => QueueTicketStatus::Created,
    ]);

    QueueTicket::factory()->create([
        'queue_session_id' => QueueSession::factory()->create([
            'tenant_id' => $this->tenant->id,
            'facility_id' => $this->facility->id,
            'department_id' => $this->generalDepartment->id,
            'workflow_id' => $this->generalWorkflow->id,
            'session_number' => 'V-TEST-0002',
            'tracking_token' => (string) Str::uuid(),
        ])->id,
        'workflow_step_id' => $this->serviceStep2->id,
        'station_id' => $this->serviceStation1->id,
        'queue_number' => 'D900',
        'status' => QueueTicketStatus::Created,
    ]);

    $this->getJson('/api/tickets?search=R900')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.queue_number', 'R900');

    $this->getJson('/api/tickets?search=Budi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.queue_number', 'R900');
});

test('service transfer search matches reason and source client identity', function () {
    $otherCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Siti Aminah', 'phone' => '081200000002']);

    $budiVisit = QueueSession::factory()->create([
        'customer_id' => $this->sampleCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-TEST-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);

    $otherVisit = QueueSession::factory()->create([
        'customer_id' => $otherCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-TEST-0002',
        'tracking_token' => (string) Str::uuid(),
    ]);

    ServiceTransfer::create([
        'source_queue_session_id' => $budiVisit->id,
        'target_department_id' => $this->supportDepartment->id,
        'reason' => 'Customer needs support',
    ]);

    ServiceTransfer::create([
        'source_queue_session_id' => $otherVisit->id,
        'target_department_id' => $this->supportDepartment->id,
        'reason' => 'Routine cleaning',
    ]);

    $this->getJson('/api/service-transfers?search=support')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.reason', 'Customer needs support');

    $this->getJson('/api/service-transfers?search=Budi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.reason', 'Customer needs support');

    $this->getJson('/api/service-transfers?search=081200000002')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.reason', 'Routine cleaning');
});

test('audit log search matches action entity user and ip', function () {
    AuditLog::create([
        'tenant_id' => $this->tenant->id,
        'action' => 'QUEUE_SESSION_PRIORITY_CHANGED',
        'entity_type' => 'queue_session',
        'entity_id' => '1',
        'user_id' => $this->adminUser->id,
        'ip_address' => '10.0.0.1',
    ]);

    AuditLog::create([
        'tenant_id' => $this->tenant->id,
        'action' => 'TICKET_CALLED',
        'entity_type' => 'queue_ticket',
        'entity_id' => '2',
        'user_id' => $this->adminUser->id,
        'ip_address' => '10.0.0.2',
    ]);

    AuditLog::create([
        'tenant_id' => $this->tenant->id,
        'action' => 'CUSTOMER_CREATED',
        'entity_type' => 'customer',
        'entity_id' => '3',
        'user_id' => $this->adminUser->id,
        'ip_address' => '192.168.1.5',
    ]);

    $this->getJson('/api/audit-logs?search=QUEUE_SESSION_PRIORITY')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/audit-logs?search=queue_ticket')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/audit-logs?search=QUEUE_SESSION_PRIORITY')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/audit-logs?search=192.168.1')
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

test('allowed sort columns order results descending', function () {
    $this->getJson('/api/departments?sort=name&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'Support Service')
        ->assertJsonPath('data.data.1.name', 'General Service');

    $this->getJson('/api/stations?sort=code&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.code', 'SVC-02');

    $this->getJson('/api/workflows?sort=name&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'Support Flow')
        ->assertJsonPath('data.data.1.name', 'General Flow');

    $sessionA = QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-TEST-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);
    $sessionB = QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-TEST-0002',
        'tracking_token' => (string) Str::uuid(),
    ]);

    $this->getJson('/api/queue-sessions?sort=session_number&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $sessionB->id)
        ->assertJsonPath('data.data.1.id', $sessionA->id);

    $transferA = ServiceTransfer::create([
        'source_queue_session_id' => $sessionA->id,
        'target_department_id' => $this->supportDepartment->id,
        'reason' => 'First',
    ]);
    $transferB = ServiceTransfer::create([
        'source_queue_session_id' => $sessionB->id,
        'target_department_id' => $this->supportDepartment->id,
        'reason' => 'Second',
    ]);

    $this->getJson('/api/service-transfers?sort=id&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $transferB->id)
        ->assertJsonPath('data.data.1.id', $transferA->id);

    AuditLog::create([
        'tenant_id' => $this->tenant->id,
        'action' => 'A_FIRST',
        'entity_type' => 'queue_session',
        'entity_id' => '1',
    ]);
    $lastLog = AuditLog::create([
        'tenant_id' => $this->tenant->id,
        'action' => 'Z_LAST',
        'entity_type' => 'queue_session',
        'entity_id' => '2',
    ]);

    $this->getJson('/api/audit-logs?sort=action&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $lastLog->id);
});

test('unknown sort columns are ignored instead of failing', function () {
    foreach (['/api/departments', '/api/stations', '/api/workflows', '/api/queue-sessions', '/api/tickets', '/api/service-transfers', '/api/audit-logs'] as $endpoint) {
        $this->getJson("{$endpoint}?sort=bogus&direction=desc")
            ->assertOk();
    }
});

test('array valued query params are rejected with a validation error', function (string $endpoint, string $param) {
    $this->getJson("{$endpoint}?{$param}[]=x")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($param);
})->with([
    ['/api/customers', 'search'],
    ['/api/queue-sessions', 'search'],
    ['/api/queue-sessions', 'status'],
    ['/api/queue-sessions', 'date'],
    ['/api/tickets', 'search'],
    ['/api/tickets', 'status'],
    ['/api/tickets', 'date'],
    ['/api/service-transfers', 'status'],
    ['/api/audit-logs', 'search'],
    ['/api/audit-logs', 'user_id'],
    ['/api/departments', 'search'],
    ['/api/stations', 'type'],
    ['/api/workflows', 'search'],
    ['/api/users', 'role'],
]);

test('invalid enum and date filters return a validation error', function (string $uri, string $param) {
    $this->getJson($uri)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($param);
})->with([
    ['/api/queue-sessions?status=BOGUS', 'status'],
    ['/api/queue-sessions?date=not-a-date', 'date'],
    ['/api/tickets?status=BOGUS', 'status'],
    ['/api/tickets?priority=9', 'priority'],
    ['/api/service-transfers?status=BOGUS', 'status'],
    ['/api/stations?type=BOGUS', 'type'],
    ['/api/users?role=BOGUS', 'role'],
]);

test('queue acquisitions paginate by default', function () {
    $visit = QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-ACQ-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);

    QueueAcquisition::create([
        'queue_session_id' => $visit->id,
        'tenant_id' => $this->tenant->id,
        'department_id' => $this->generalDepartment->id,
        'channel' => IntakeChannel::Manual,
        'status' => QueueAcquisitionStatus::Acquired,
    ]);

    $this->getJson('/api/queue-acquisitions?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.data.0.queue_session_id', $visit->id);
});

test('a visit exposes its active acquisition through a dedicated endpoint', function () {
    $visit = QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-ACQ-0002',
        'tracking_token' => (string) Str::uuid(),
    ]);

    $acquisition = QueueAcquisition::create([
        'queue_session_id' => $visit->id,
        'tenant_id' => $this->tenant->id,
        'department_id' => $this->generalDepartment->id,
        'channel' => IntakeChannel::Manual,
        'status' => QueueAcquisitionStatus::Acquired,
    ]);

    $this->getJson("/api/queue-sessions/{$visit->id}/acquisition")
        ->assertOk()
        ->assertJsonPath('data.id', $acquisition->id);

    $acquisition->update(['status' => QueueAcquisitionStatus::Registered]);

    $this->getJson("/api/queue-sessions/{$visit->id}/acquisition")
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('client visit history and station queue are paginated', function () {
    $client = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '081200000001',
        'name' => 'Paged Client',
    ]);

    foreach (range(1, 3) as $i) {
        QueueSession::factory()->create([
            'tenant_id' => $this->tenant->id,
            'facility_id' => $this->facility->id,
            'customer_id' => $client->id,
            'department_id' => $this->generalDepartment->id,
            'workflow_id' => $this->generalWorkflow->id,
            'session_number' => "V-PAGE-000{$i}",
            'tracking_token' => (string) Str::uuid(),
        ]);
    }

    $this->getJson("/api/customers/{$client->id}/queue-sessions?per_page=2")
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonCount(2, 'data.data');

    $this->getJson("/api/stations/{$this->registrationStation->id}/queue?per_page=1")
        ->assertOk()
        ->assertJsonStructure(['station', 'data' => ['data', 'per_page', 'total']]);
});
