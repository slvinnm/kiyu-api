<?php

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\TenantOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Provision a tenant with an owner, a facility and a second facility, so the
 * facility-scoped rules can be exercised.
 *
 * @return array{tenant: Tenant, facility: Facility, otherFacility: Facility, owner: User}
 */
function tenancyFixture(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Tenancy Test Org',
        'slug' => 'tenancy-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $otherFacility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Branch',
        'code' => 'BRANCH',
        'is_active' => true,
    ]);

    $owner = User::factory()->create([
        'role' => UserRole::TenantAdmin,
        'tenant_id' => $tenant->id,
    ]);

    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    return compact('tenant', 'facility', 'otherFacility', 'owner');
}

test('a tenant admin has implicit access to every facility of the tenant', function () {
    ['tenant' => $tenant, 'owner' => $owner, 'otherFacility' => $otherFacility] = tenancyFixture();

    expect($owner->hasTenantWideAccess())->toBeTrue()
        ->and($owner->canAccessFacility($otherFacility->id))->toBeTrue()
        // ...but never to a facility of another tenant.
        ->and($owner->canAccessFacility(Facility::factory()->create()->id))->toBeFalse();
});

test('a facility-scoped user may only act at assigned facilities', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'otherFacility' => $otherFacility] = tenancyFixture();

    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'tenant_id' => $tenant->id,
    ]);

    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $operator->id,
        'facility_id' => $facility->id,
    ]);

    expect($operator->canAccessFacility($facility->id))->toBeTrue()
        ->and($operator->canAccessFacility($otherFacility->id))->toBeFalse();
});

test('tenant provisioning creates a canonical owner in the same tenant', function () {
    $result = app(TenantOnboardingService::class)->provision([
        'name' => 'Owner Invariant Org',
        'facility_name' => 'Main',
        'admin_name' => 'Owner',
        'admin_email' => 'owner@invariant.test',
        'admin_password' => 'password123',
    ]);

    $tenant = $result['tenant']->fresh();

    expect($tenant->owner_user_id)->toBe($result['tenant_admin']->id)
        ->and($tenant->owner->tenant_id)->toBe($tenant->id)
        ->and($tenant->owner->role)->toBe(UserRole::TenantAdmin);
});

test('public registration is refused unless the tenant allows it', function () {
    ['tenant' => $tenant] = tenancyFixture();

    $payload = [
        'name' => 'Self Service',
        'email' => 'self@service.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/auth/register', $payload)
        ->assertForbidden();

    $tenant->update(['allow_public_registration' => true]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/auth/register', $payload + ['role' => 'tenant_admin'])
        ->assertCreated()
        ->assertJsonPath('data.user.role', 'client');
});

test('an invitation is single-use, tenant-scoped and carries its facility grant', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'owner' => $owner] = tenancyFixture();

    $created = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/invitations', [
            'email' => 'invited@operator.test',
            'role' => 'operator',
            'facility_ids' => [$facility->id],
        ])
        ->assertCreated();

    $token = $created->json('data.token');
    expect($token)->toBeString();

    // The stored invitation only holds the hash.
    $invitation = TenantInvitation::query()->firstOrFail();
    expect($invitation->token_hash)->not->toBe($token);

    $this->postJson('/api/auth/register/invitation', [
        'token' => $token,
        'name' => 'Invited Operator',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::query()->where('email', 'invited@operator.test')->firstOrFail();
    expect($user->role)->toBe(UserRole::Operator)
        ->and($user->tenant_id)->toBe($tenant->id)
        ->and($user->canAccessFacility($facility->id))->toBeTrue();

    // Replaying the same token is refused.
    $this->postJson('/api/auth/register/invitation', [
        'token' => $token,
        'name' => 'Replay',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422);
});

test('a facility admin cannot invite a privileged role', function () {
    ['tenant' => $tenant, 'facility' => $facility] = tenancyFixture();

    $facilityAdmin = User::factory()->create([
        'role' => UserRole::FacilityAdmin,
        'tenant_id' => $tenant->id,
    ]);

    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $facilityAdmin->id,
        'facility_id' => $facility->id,
    ]);

    $this->actingAs($facilityAdmin, 'sanctum')
        ->postJson('/api/invitations', [
            'email' => 'escalate@tenant.test',
            'role' => 'tenant_admin',
        ])
        ->assertForbidden();
});

test('the platform support context is audited with the acting platform user', function () {
    ['tenant' => $tenant] = tenancyFixture();

    $platform = User::factory()->create([
        'role' => UserRole::PlatformAdmin,
        'tenant_id' => null,
    ]);

    $this->actingAs($platform, 'sanctum')
        ->getJson("/api/platform/tenants/{$tenant->id}/manage/users")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $tenant->id,
        'action' => 'PLATFORM_SUPPORT_ACCESS',
        'platform_user_id' => $platform->id,
    ]);
});

test('a customer record never requires medical identifiers', function () {
    $columns = Schema::getColumns('customers');
    $names = array_column($columns, 'name');

    expect($names)->not->toContain('mrn')
        ->not->toContain('nik')
        ->not->toContain('date_of_birth')
        ->not->toContain('gender');
});
