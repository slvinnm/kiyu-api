<?php

use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Station;
use App\Models\Tenant;
use App\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, station: Station}
 */
function jakartaClinic(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Jakarta Clinic',
        'slug' => 'jakarta-clinic',
        'status' => TenantStatus::Active,
        'timezone' => 'Asia/Jakarta',
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $department = Department::create([
        'facility_id' => $facility->id,
        'code' => 'GEN',
        'name' => 'General',
        'is_active' => true,
    ]);

    $station = Station::create([
        'department_id' => $department->id,
        'name' => 'Registration',
        'code' => 'REG-01',
        'type' => StationType::Registration,
        'queue_prefix' => 'R',
        'is_active' => true,
    ]);

    return compact('tenant', 'facility', 'station');
}

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
});

test('visit numbers use the clinic local date, not the UTC date', function () {
    $clinic = jakartaClinic();

    // 02:00 on 24 Nov in Jakarta is still 19:00 on 23 Nov in UTC.
    Carbon::setTestNow(Carbon::parse('2026-11-23 19:00:00', 'UTC'));

    $number = app(SequenceService::class)->generateVisitNumber($clinic['facility']);

    expect($number)->toStartWith('V-20261124-');

    Carbon::setTestNow();
});

test('the visit counter rolls over at local midnight', function () {
    $clinic = jakartaClinic();
    $service = app(SequenceService::class);

    // 23:59 Jakarta on 24 Nov.
    Carbon::setTestNow(Carbon::parse('2026-11-24 16:59:00', 'UTC'));
    expect($service->generateVisitNumber($clinic['facility']))->toEndWith('0001');

    // 00:01 Jakarta on 25 Nov -> new day, sequence restarts.
    Carbon::setTestNow(Carbon::parse('2026-11-24 17:01:00', 'UTC'));
    expect($service->generateVisitNumber($clinic['facility']))->toEndWith('0001')
        ->and($service->generateVisitNumber($clinic['facility']))->toStartWith('V-20261125-');

    Carbon::setTestNow();
});
