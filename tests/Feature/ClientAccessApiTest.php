<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Visit;
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

    $this->ownClient = Client::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->clientUser->id,
    ]);

    $this->otherClient = Client::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->ownVisit = Visit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->ownClient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-ACCESS-0001',
        'visit_token' => (string) Str::uuid(),
    ]);

    $this->otherVisit = Visit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->otherClient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
        'visit_number' => 'V-ACCESS-0002',
        'visit_token' => (string) Str::uuid(),
    ]);
});

test('a client cannot enumerate the client directory', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/clients')
        ->assertForbidden();
});

test('a client can read only their own client record', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/clients/{$this->ownClient->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->ownClient->id);

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/clients/{$this->otherClient->id}")
        ->assertForbidden();
});

test('a client cannot update or list visits of another client', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->putJson("/api/clients/{$this->otherClient->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/clients/{$this->otherClient->id}/visits")
        ->assertForbidden();
});

test('a client visit list is scoped to their own record', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson('/api/visits')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $this->ownVisit->id);

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/visits?client_id={$this->otherClient->id}")
        ->assertForbidden();
});

test('a client cannot open another client visit detail', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/visits/{$this->ownVisit->id}")
        ->assertOk();

    $this->actingAs($this->clientUser, 'sanctum')
        ->getJson("/api/visits/{$this->otherVisit->id}")
        ->assertForbidden();
});

test('a client cannot book an appointment for another client', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/intake/online', [
            'client_id' => $this->otherClient->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertForbidden();

    $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/intake/online', [
            'client_id' => $this->ownClient->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertCreated();
});

test('a client cannot attach a profile to another user', function () {
    $this->actingAs($this->clientUser, 'sanctum')
        ->postJson('/api/clients', [
            'name' => 'Impostor',
            'user_id' => $this->sampleClient->user_id ?? User::factory()->create()->id,
        ])
        ->assertForbidden();
});

test('staff access to client records is unchanged', function () {
    // The setup authenticates an admin; staff read paths keep working.
    $this->getJson("/api/clients/{$this->otherClient->id}")
        ->assertOk();

    $this->getJson('/api/visits')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});
