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

    // Client A signs in; their profile is linked to their user account.
    $this->clientUser = User::factory()->create([
        'role' => 'client',
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

test('a client cannot enumerate the client directory', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/customers')
        ->assertForbidden();
});

test('a client can read only their own client record', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/customers/{$this->ownCustomer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->ownCustomer->id);

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/customers/{$this->otherCustomer->id}")
        ->assertForbidden();
});

test('a client cannot update or list visits of another client', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->putJson("/api/customers/{$this->otherCustomer->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/customers/{$this->otherCustomer->id}/queue-sessions")
        ->assertForbidden();
});

test('a client visit list is scoped to their own record', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/queue-sessions')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $this->ownVisit->id);

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/queue-sessions?customer_id={$this->otherCustomer->id}")
        ->assertForbidden();
});

test('a client cannot open another client visit detail', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/queue-sessions/{$this->ownVisit->id}")
        ->assertOk();

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/queue-sessions/{$this->otherVisit->id}")
        ->assertForbidden();
});

test('a client cannot book an appointment for another client', function () {
    // The body client_id is ignored for a client account: the booking is
    // always created against the caller's own profile.
    $response = $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/intake/online', [
            'customer_id' => $this->otherCustomer->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertCreated();

    expect($response->json('data.queue_session.customer_id'))->toBe($this->ownCustomer->id);

    $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/intake/online', [
            'customer_id' => $this->ownCustomer->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertCreated();
});

test('online booking requires authentication', function () {
    // The setup authenticates an admin for the other tests; drop the guard so
    // this request is genuinely unauthenticated.
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/intake/online', [
        'customer_id' => $this->otherCustomer->id,
        'department_id' => $this->generalDepartment->id,
    ])->assertUnauthorized();
});

test('a client cannot attach a profile to another user', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/customers', [
            'name' => 'Impostor',
            'user_id' => $this->sampleCustomer->user_id ?? User::factory()->create()->id,
        ])
        ->assertForbidden();
});

test('staff access to client records is unchanged', function () {
    // The setup authenticates an admin; staff read paths keep working.
    $this->getJson("/api/customers/{$this->otherCustomer->id}")
        ->assertOk();

    $this->getJson('/api/queue-sessions')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('staff can link a client to a user in the same tenant', function () {
    $member = User::factory()->create([
        'role' => 'client',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->postJson('/api/customers', [
        'name' => 'Linked Client',
        'user_id' => $member->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $member->id);
});

test('staff cannot link a client to a user from another tenant', function () {
    $foreignTenant = Tenant::query()->create([
        'name' => 'Foreign Clinic',
        'slug' => 'foreign-clinic',
        'status' => TenantStatus::Active,
    ]);

    $foreignUser = User::factory()->create([
        'role' => 'client',
        'tenant_id' => $foreignTenant->id,
    ]);

    $this->postJson('/api/customers', [
        'name' => 'Impostor Target',
        'user_id' => $foreignUser->id,
    ])
        ->assertStatus(422);

    $this->assertDatabaseMissing('customers', ['user_id' => $foreignUser->id]);
});

test('deleting a client keeps the row as a soft delete', function () {
    $this->ownCustomer->delete();

    expect(Customer::query()->whereKey($this->ownCustomer->id)->exists())->toBeFalse()
        ->and(Customer::withTrashed()->find($this->ownCustomer->id))->not->toBeNull();

    $this->assertSoftDeleted('customers', ['id' => $this->ownCustomer->id]);
});
