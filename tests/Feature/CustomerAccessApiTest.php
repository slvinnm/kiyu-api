<?php

use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\QueueSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();

    // The customer account is linked to their own `customers` row.
    $this->clientUser = User::factory()->create([
        'role' => 'customer',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->ownCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->clientUser->id,
    ]);

    $this->otherCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->ownVisit = QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->ownCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-ACCESS-0001',
        'tracking_token' => (string) Str::uuid(),
    ]);

    $this->otherVisit = QueueSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->otherCustomer->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'session_number' => 'V-ACCESS-0002',
        'tracking_token' => (string) Str::uuid(),
    ]);
});

test('a customer cannot enumerate the customer directory', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/tenant/customers')
        ->assertForbidden();
});

test('a customer can read only their own profile', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $this->ownCustomer->id);
});

test('a customer cannot update another customer record', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->putJson("/api/tenant/customers/{$this->otherCustomer->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/tenant/customers/{$this->otherCustomer->id}/queue-sessions")
        ->assertForbidden();
});

test('a customer visit list is scoped to their own record', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/customer/queue-sessions')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $this->ownVisit->id);
});

test('a customer cannot open another customer visit detail', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/customer/queue-sessions/{$this->ownVisit->id}")
        ->assertOk();

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/customer/queue-sessions/{$this->otherVisit->id}")
        ->assertNotFound();
});

test('a customer booking is always created for their own profile', function () {
    $response = $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/customer/bookings', [
            'customer_id' => $this->otherCustomer->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertCreated();

    expect($response->json('data.queue_session.customer_id'))->toBe($this->ownCustomer->id);
});

test('online booking requires authentication', function () {
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/customer/bookings', [
        'department_id' => $this->generalDepartment->id,
    ])->assertUnauthorized();
});

test('staff access to customer records is unchanged', function () {
    $this->getJson("/api/tenant/customers/{$this->otherCustomer->id}")
        ->assertOk();

    $this->getJson('/api/tenant/queue-sessions')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('staff can link a customer to a user in the same tenant', function () {
    $member = User::factory()->create([
        'role' => 'customer',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->postJson('/api/tenant/customers', [
        'name' => 'Linked Customer',
        'user_id' => $member->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $member->id);
});

test('staff cannot link a customer to a user from another tenant', function () {
    $foreignTenant = Tenant::query()->create([
        'name' => 'Foreign Clinic',
        'slug' => 'foreign-clinic',
        'status' => TenantStatus::Active,
    ]);

    $foreignUser = User::factory()->create([
        'role' => 'customer',
        'tenant_id' => $foreignTenant->id,
    ]);

    $this->postJson('/api/tenant/customers', [
        'name' => 'Impostor Target',
        'user_id' => $foreignUser->id,
    ])
        ->assertStatus(422);

    $this->assertDatabaseMissing('customers', ['user_id' => $foreignUser->id]);
});

test('deleting a customer keeps the row as a soft delete', function () {
    $this->ownCustomer->delete();

    expect(Customer::query()->whereKey($this->ownCustomer->id)->exists())->toBeFalse()
        ->and(Customer::withTrashed()->find($this->ownCustomer->id))->not->toBeNull();

    $this->assertSoftDeleted('customers', ['id' => $this->ownCustomer->id]);
});
