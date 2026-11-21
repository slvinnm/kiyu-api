<?php

use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetupKiyuHospital;

uses(RefreshDatabase::class, SetupKiyuHospital::class);

beforeEach(function () {
    $this->setupHospital();

    // Patient A signs in; their profile is linked to their user account.
    $this->patientUser = User::factory()->create(['role' => 'patient']);
    $this->ownPatient = Patient::factory()->create(['user_id' => $this->patientUser->id]);
    $this->otherPatient = Patient::factory()->create();

    $this->ownVisit = Visit::factory()->create([
        'patient_id' => $this->ownPatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
    ]);

    $this->otherVisit = Visit::factory()->create([
        'patient_id' => $this->otherPatient->id,
        'department_id' => $this->generalDepartment->id,
        'workflow_id' => $this->generalWorkflow->id,
    ]);
});

test('a patient cannot enumerate the patient directory', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson('/api/patients')
        ->assertForbidden();
});

test('a patient can read only their own patient record', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson("/api/patients/{$this->ownPatient->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->ownPatient->id);

    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson("/api/patients/{$this->otherPatient->id}")
        ->assertForbidden();
});

test('a patient cannot update or list visits of another patient', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->putJson("/api/patients/{$this->otherPatient->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson("/api/patients/{$this->otherPatient->id}/visits")
        ->assertForbidden();
});

test('a patient visit list is scoped to their own record', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson('/api/visits')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $this->ownVisit->id);

    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson("/api/visits?patient_id={$this->otherPatient->id}")
        ->assertForbidden();
});

test('a patient cannot open another patient visit detail', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson("/api/visits/{$this->ownVisit->id}")
        ->assertOk();

    $this->actingAs($this->patientUser, 'sanctum')
        ->getJson("/api/visits/{$this->otherVisit->id}")
        ->assertForbidden();
});

test('a patient cannot book an appointment for another patient', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->postJson('/api/intake/online', [
            'patient_id' => $this->otherPatient->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertForbidden();

    $this->actingAs($this->patientUser, 'sanctum')
        ->postJson('/api/intake/online', [
            'patient_id' => $this->ownPatient->id,
            'department_id' => $this->generalDepartment->id,
        ])
        ->assertCreated();
});

test('a patient cannot attach a profile to another user', function () {
    $this->actingAs($this->patientUser, 'sanctum')
        ->postJson('/api/patients', [
            'name' => 'Impostor',
            'user_id' => $this->samplePatient->user_id ?? User::factory()->create()->id,
        ])
        ->assertForbidden();
});

test('staff access to patient records is unchanged', function () {
    // The setup authenticates an admin; staff read paths keep working.
    $this->getJson("/api/patients/{$this->otherPatient->id}")
        ->assertOk();

    $this->getJson('/api/visits')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});
