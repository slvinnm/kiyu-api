<?php

use App\Enums\IntakeChannel;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueTicketStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Patient;
use App\Models\QueueAcquisition;
use App\Models\QueueTicket;
use App\Models\Referral;
use App\Models\Visit;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();
});

test('master data table endpoints paginate by default', function () {
    Department::create(['code' => 'POLI_ANAK', 'name' => 'Poli Anak', 'is_active' => true]);
    Department::create(['code' => 'POLI_SARAF', 'name' => 'Poli Saraf', 'is_active' => true]);
    Department::create(['code' => 'POLI_JANTUNG', 'name' => 'Poli Jantung', 'is_active' => true]);

    $this->getJson('/api/departments?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.last_page', 3)
        ->assertJsonCount(2, 'data.data');

    $this->getJson('/api/stations?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 7)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.last_page', 4)
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
        ->assertJsonPath('data.0.code', 'POLI_GIGI')
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'is_active', 'display_in_kiosk']]]);

    $this->getJson('/api/stations/options')
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonStructure(['data' => [['id', 'department_id', 'code', 'name', 'type', 'is_active']]]);

    $this->getJson("/api/stations/options?department_id={$this->dentalDepartment->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/stations/options?type=NURSE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'NUR-01');
});

test('statistics endpoints return aggregate counts', function () {
    Patient::factory()->count(3)->create();
    Visit::factory()->create([
        'patient_id' => $this->samplePatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-STATS-0001',
        'visit_token' => (string) Str::uuid(),
    ]);

    $this->getJson('/api/workflows/statistics')
        ->assertOk()
        ->assertJsonPath('data.total', 2);

    $this->getJson('/api/patients/statistics')
        ->assertOk()
        ->assertJsonPath('data.total', 4);

    $this->getJson('/api/visits/statistics')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/visits/statistics?status=CANCELLED')
        ->assertOk()
        ->assertJsonPath('data.total', 0);

    QueueTicket::factory()->create([
        'visit_id' => Visit::query()->firstOrFail()->id,
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
    $this->getJson('/api/departments?search=Gigi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'POLI_GIGI');

    $this->getJson('/api/departments?search=POLI_UMUM')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'POLI_UMUM');

    $this->getJson('/api/departments?search=%20%20')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('station search matches own columns and related department name', function () {
    $this->getJson('/api/stations?search=Perawat')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'NUR-01');

    $this->getJson('/api/stations?search=NURSE')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'NUR-01');

    $this->getJson('/api/stations?search=Poli%20Gigi')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('workflow search matches name and related department name', function () {
    $this->getJson('/api/workflows?search=Gigi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.name', 'Alur Rawat Gigi');

    $this->getJson('/api/workflows?search=Poli%20Umum')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.name', 'Alur Rawat Jalan Umum');
});

test('visit search matches visit number and patient identity', function () {
    $otherPatient = Patient::factory()->create(['name' => 'Siti Aminah', 'mrn' => 'MRN-20002', 'nik' => '3201010101900002']);

    $budiVisit = Visit::factory()->create([
        'patient_id' => $this->samplePatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-TEST-0001',
        'visit_token' => (string) Str::uuid(),
    ]);

    Visit::factory()->create([
        'patient_id' => $otherPatient->id,
        'department_id' => $this->dentalDepartment->id,
        'workflow_id' => $this->dentalWorkflow->id,
        'visit_number' => 'V-TEST-0002',
        'visit_token' => (string) Str::uuid(),
    ]);

    $this->getJson('/api/visits?search=V-TEST-0001')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $budiVisit->id);

    $this->getJson('/api/visits?search=Budi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $budiVisit->id);

    $this->getJson('/api/visits?search=MRN-20002')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.patient_id', $otherPatient->id);
});

test('ticket search matches queue number and patient name', function () {
    $visit = Visit::factory()->create([
        'patient_id' => $this->samplePatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-TEST-0001',
        'visit_token' => (string) Str::uuid(),
    ]);

    QueueTicket::factory()->create([
        'visit_id' => $visit->id,
        'workflow_step_id' => $this->regStep->id,
        'station_id' => $this->registrationStation->id,
        'queue_number' => 'R900',
        'status' => QueueTicketStatus::Created,
    ]);

    QueueTicket::factory()->create([
        'visit_id' => Visit::factory()->create([
            'department_id' => $this->generalDepartment->id,
            'workflow_id' => $this->generalWorkflow->id,
            'visit_number' => 'V-TEST-0002',
            'visit_token' => (string) Str::uuid(),
        ])->id,
        'workflow_step_id' => $this->docStep->id,
        'station_id' => $this->doctorStation1->id,
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

test('referral search matches reason and source patient identity', function () {
    $otherPatient = Patient::factory()->create(['name' => 'Siti Aminah', 'mrn' => 'MRN-20002']);

    $budiVisit = Visit::factory()->create([
        'patient_id' => $this->samplePatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-TEST-0001',
        'visit_token' => (string) Str::uuid(),
    ]);

    $otherVisit = Visit::factory()->create([
        'patient_id' => $otherPatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-TEST-0002',
        'visit_token' => (string) Str::uuid(),
    ]);

    Referral::create([
        'source_visit_id' => $budiVisit->id,
        'target_department_id' => $this->dentalDepartment->id,
        'reason' => 'Patient needs dental extraction',
    ]);

    Referral::create([
        'source_visit_id' => $otherVisit->id,
        'target_department_id' => $this->dentalDepartment->id,
        'reason' => 'Routine cleaning',
    ]);

    $this->getJson('/api/referrals?search=extraction')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.reason', 'Patient needs dental extraction');

    $this->getJson('/api/referrals?search=Budi')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.reason', 'Patient needs dental extraction');

    $this->getJson('/api/referrals?search=MRN-20002')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.reason', 'Routine cleaning');
});

test('audit log search matches action entity user and ip', function () {
    AuditLog::create([
        'action' => 'VISIT_PRIORITY_CHANGED',
        'entity_type' => 'visit',
        'entity_id' => '1',
        'user_id' => 'admin-carol',
        'ip_address' => '10.0.0.1',
    ]);

    AuditLog::create([
        'action' => 'TICKET_CALLED',
        'entity_type' => 'queue_ticket',
        'entity_id' => '2',
        'user_id' => 'nurse-bob',
        'ip_address' => '10.0.0.2',
    ]);

    AuditLog::create([
        'action' => 'PATIENT_CREATED',
        'entity_type' => 'patient',
        'entity_id' => '3',
        'user_id' => 'receptionist-1',
        'ip_address' => '192.168.1.5',
    ]);

    $this->getJson('/api/audit-logs?search=VISIT_PRIORITY')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/audit-logs?search=queue_ticket')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/audit-logs?search=nurse-bob')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/audit-logs?search=192.168.1')
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

test('allowed sort columns order results descending', function () {
    $this->getJson('/api/departments?sort=name&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'Poli Umum')
        ->assertJsonPath('data.data.1.name', 'Poli Gigi');

    $this->getJson('/api/stations?sort=code&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.code', 'REG-GIGI');

    $this->getJson('/api/workflows?sort=name&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'Alur Rawat Jalan Umum')
        ->assertJsonPath('data.data.1.name', 'Alur Rawat Gigi');

    $visitA = Visit::factory()->create([
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-TEST-0001',
        'visit_token' => (string) Str::uuid(),
    ]);
    $visitB = Visit::factory()->create([
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-TEST-0002',
        'visit_token' => (string) Str::uuid(),
    ]);

    $this->getJson('/api/visits?sort=visit_number&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $visitB->id)
        ->assertJsonPath('data.data.1.id', $visitA->id);

    $referralA = Referral::create([
        'source_visit_id' => $visitA->id,
        'target_department_id' => $this->dentalDepartment->id,
        'reason' => 'First',
    ]);
    $referralB = Referral::create([
        'source_visit_id' => $visitB->id,
        'target_department_id' => $this->dentalDepartment->id,
        'reason' => 'Second',
    ]);

    $this->getJson('/api/referrals?sort=id&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $referralB->id)
        ->assertJsonPath('data.data.1.id', $referralA->id);

    AuditLog::create([
        'action' => 'A_FIRST',
        'entity_type' => 'visit',
        'entity_id' => '1',
    ]);
    $lastLog = AuditLog::create([
        'action' => 'Z_LAST',
        'entity_type' => 'visit',
        'entity_id' => '2',
    ]);

    $this->getJson('/api/audit-logs?sort=action&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $lastLog->id);
});

test('unknown sort columns are ignored instead of failing', function () {
    foreach (['/api/departments', '/api/stations', '/api/workflows', '/api/visits', '/api/tickets', '/api/referrals', '/api/audit-logs'] as $endpoint) {
        $this->getJson("{$endpoint}?sort=bogus&direction=desc")
            ->assertOk();
    }
});

test('array valued query params are rejected with a validation error', function (string $endpoint, string $param) {
    $this->getJson("{$endpoint}?{$param}[]=x")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($param);
})->with([
    ['/api/patients', 'search'],
    ['/api/visits', 'search'],
    ['/api/visits', 'status'],
    ['/api/visits', 'date'],
    ['/api/tickets', 'search'],
    ['/api/tickets', 'status'],
    ['/api/tickets', 'date'],
    ['/api/referrals', 'status'],
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
    ['/api/visits?status=BOGUS', 'status'],
    ['/api/visits?date=not-a-date', 'date'],
    ['/api/tickets?status=BOGUS', 'status'],
    ['/api/tickets?priority=9', 'priority'],
    ['/api/referrals?status=BOGUS', 'status'],
    ['/api/stations?type=BOGUS', 'type'],
    ['/api/users?role=BOGUS', 'role'],
]);

test('queue acquisitions paginate by default', function () {
    $visit = Visit::factory()->create([
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-ACQ-0001',
        'visit_token' => (string) Str::uuid(),
    ]);

    QueueAcquisition::create([
        'visit_id' => $visit->id,
        'department_id' => $this->generalDepartment->id,
        'channel' => IntakeChannel::Manual,
        'status' => QueueAcquisitionStatus::Acquired,
    ]);

    $this->getJson('/api/queue-acquisitions?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.data.0.visit_id', $visit->id);
});

test('a visit exposes its active acquisition through a dedicated endpoint', function () {
    $visit = Visit::factory()->create([
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-ACQ-0002',
        'visit_token' => (string) Str::uuid(),
    ]);

    $acquisition = QueueAcquisition::create([
        'visit_id' => $visit->id,
        'department_id' => $this->generalDepartment->id,
        'channel' => IntakeChannel::Manual,
        'status' => QueueAcquisitionStatus::Acquired,
    ]);

    $this->getJson("/api/visits/{$visit->id}/acquisition")
        ->assertOk()
        ->assertJsonPath('data.id', $acquisition->id);

    $acquisition->update(['status' => QueueAcquisitionStatus::Registered]);

    $this->getJson("/api/visits/{$visit->id}/acquisition")
        ->assertOk()
        ->assertJsonPath('data', null);
});
