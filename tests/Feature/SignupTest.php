<?php

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedTrialPlan(): void
{
    $plan = Plan::query()->create([
        'code' => 'TRIAL',
        'name' => 'Trial',
        'is_active' => true,
    ]);

    foreach (['max_facilities' => 1, 'max_staff' => 5, 'max_kiosk_devices' => 1, 'max_display_devices' => 1] as $code => $limit) {
        $plan->entitlements()->create(['feature_code' => $code, 'value' => ['limit' => $limit]]);
    }
    $plan->entitlements()->create(['feature_code' => 'queue_management', 'value' => null]);
}

test('public signup creates an organization, owner, facility and a trialing subscription', function () {
    seedTrialPlan();

    $response = $this->postJson('/api/app/auth/signup', [
        'organization_name' => 'Acme Services',
        'owner_name' => 'Ada Owner',
        'owner_email' => 'ada@acme.test',
        'owner_password' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.tenant.name', 'Acme Services')
        ->assertJsonPath('data.tenant.status', 'ACTIVE')
        ->assertJsonPath('data.subscription.status', 'TRIALING');

    $tenant = Tenant::query()->where('slug', 'acme-services')->firstOrFail();
    expect($tenant->owner_user_id)->not->toBeNull();

    $owner = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    expect($owner->role->value)->toBe('tenant_admin')
        ->and($owner->tenant_id)->toBe($tenant->id);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $tenant->id,
        'status' => SubscriptionStatus::Trialing->value,
    ]);

    // A trial org is operational immediately.
    expect($tenant->status)->toBe(TenantStatus::Active);
});

test('public signup rejects a reserved slug', function () {
    seedTrialPlan();

    $this->postJson('/api/app/auth/signup', [
        'organization_name' => 'Acme',
        'slug' => 'app',
        'owner_name' => 'Ada',
        'owner_email' => 'ada2@acme.test',
        'owner_password' => 'password123',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');
});

test('public signup rejects a duplicate owner email', function () {
    seedTrialPlan();
    User::factory()->create(['email' => 'dupe@acme.test']);

    $this->postJson('/api/app/auth/signup', [
        'organization_name' => 'Another Org',
        'owner_name' => 'Dupe',
        'owner_email' => 'dupe@acme.test',
        'owner_password' => 'password123',
    ])->assertStatus(409);
});
