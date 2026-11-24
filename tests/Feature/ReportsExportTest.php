<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Models\Workflow;
use App\Services\ExportService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User, department: Department, workflow: Workflow}
 */
function exportClinic(bool $advanced = true): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Export Clinic',
        'slug' => 'export-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

    $department = Department::create([
        'facility_id' => $facility->id,
        'code' => 'GEN',
        'name' => 'General',
        'is_active' => true,
    ]);

    $workflow = Workflow::create([
        'department_id' => $department->id,
        'name' => 'Flow',
        'is_active' => true,
    ]);

    $plan = Plan::query()->create(['code' => 'E-' . uniqid(), 'name' => 'E', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::BasicReports->value, 'value' => null]);

    if ($advanced) {
        $plan->entitlements()->create(['feature_code' => Feature::AdvancedReports->value, 'value' => null]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin', 'department', 'workflow');
}

/**
 * @param  array<string, mixed>  $clinic
 */
function exportVisit(array $clinic): Visit
{
    $client = Client::query()->create([
        'tenant_id' => $clinic['tenant']->id,
        'mrn' => 'MRN-' . Str::random(6),
        'name' => 'Export Client',
    ]);

    return Visit::query()->create([
        'tenant_id' => $clinic['tenant']->id,
        'facility_id' => $clinic['facility']->id,
        'client_id' => $client->id,
        'department_id' => $clinic['department']->id,
        'workflow_id' => $clinic['workflow']->id,
        'visit_number' => 'V-' . Str::random(8),
        'visit_token' => (string) Str::uuid(),
        'priority' => 1,
        'intake_channel' => 'MANUAL',
        'status' => 'COMPLETED',
        'checked_in_at' => now(),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
});

test('generating an excel export stores the file and returns a download url', function () {
    $clinic = exportClinic();
    exportVisit($clinic);

    $response = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/reports/visits/export-file', ['format' => 'xlsx'])
        ->assertCreated()
        ->assertJsonPath('data.format', 'xlsx');

    $path = $response->json('data.path');

    expect($path)->toEndWith('.xlsx');
    Storage::disk('local')->assertExists($path);
});

test('generating a pdf export stores the file', function () {
    $clinic = exportClinic();
    exportVisit($clinic);

    $path = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/reports/visits/export-file', ['format' => 'pdf'])
        ->assertCreated()
        ->json('data.path');

    expect($path)->toEndWith('.pdf');
    Storage::disk('local')->assertExists($path);
});

test('a stored export can be downloaded by its own tenant', function () {
    $clinic = exportClinic();
    exportVisit($clinic);

    $path = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/reports/visits/export-file', ['format' => 'pdf'])
        ->json('data.path');

    $this->actingAs($clinic['admin'], 'sanctum')
        ->get("/api/reports/download?path={$path}")
        ->assertOk();
});

test('a tenant cannot download another tenant export', function () {
    $a = exportClinic();
    exportVisit($a);
    $path = $this->actingAs($a['admin'], 'sanctum')
        ->postJson('/api/reports/visits/export-file', ['format' => 'pdf'])
        ->json('data.path');

    $b = exportClinic();

    $this->actingAs($b['admin'], 'sanctum')
        ->get("/api/reports/download?path={$path}")
        ->assertForbidden();
});

test('a tenant id that is a string prefix of another cannot read its exports', function () {
    // `exports/1` is a prefix of `exports/12`, so the guard must compare the
    // full directory segment, not a bare string prefix.
    Storage::disk('local')->put('exports/12/secret.pdf', 'tenant twelve');

    $service = app(ExportService::class);

    expect(fn() => $service->absolutePath(1, 'exports/12/secret.pdf'))
        ->toThrow(HttpException::class);
});

test('an export path containing traversal is refused', function () {
    $service = app(ExportService::class);

    expect(fn() => $service->absolutePath(1, 'exports/1/../../.env'))
        ->toThrow(HttpException::class);
});

test('the export file endpoint requires advanced_reports', function () {
    $clinic = exportClinic(advanced: false);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/reports/visits/export-file', ['format' => 'xlsx'])
        ->assertForbidden();
});

test('purging removes only old exports', function () {
    Storage::disk('local')->put('exports/1/old.pdf', 'x');
    Storage::disk('local')->put('exports/1/new.pdf', 'x');

    // Backdate the old file to 10 days ago.
    $oldPath = Storage::disk('local')->path('exports/1/old.pdf');
    touch($oldPath, now()->subDays(10)->getTimestamp());

    $removed = app(ExportService::class)->purgeOlderThan(7);

    expect($removed)->toBe(1);
    Storage::disk('local')->assertMissing('exports/1/old.pdf');
    Storage::disk('local')->assertExists('exports/1/new.pdf');
});
