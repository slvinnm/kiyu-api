<?php

namespace App\Services\Configuration;

use App\Models\Department;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\WorkflowService;

/**
 * Workflow structure writes scoped to one facility.
 *
 * The ticket-flow resolution logic already lives in {@see WorkflowService};
 * this service only covers the administration endpoints (create workflow, add
 * or edit steps) shared by the App and Tenant controllers.
 */
class WorkflowAdminService
{
    /**
     * Create a workflow in a department of the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $facilityId, array $data): Workflow
    {
        $this->assertDepartmentInFacility($facilityId, (int) $data['department_id']);

        $workflow = Workflow::query()->create([
            'department_id' => $data['department_id'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $workflow->load('department');
    }

    /**
     * Add a step to a workflow of the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function addStep(int $facilityId, int $workflowId, array $data): WorkflowStep
    {
        $workflow = Workflow::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $facilityId))
            ->findOrFail($workflowId);

        $step = $workflow->steps()->create([
            'station_id' => $data['station_id'],
            'name' => $data['name'],
            'sequence' => $data['sequence'],
            'is_active' => $data['is_active'] ?? true,
            'is_optional' => $data['is_optional'] ?? false,
            'can_skip' => $data['can_skip'] ?? false,
        ]);

        return $step->load('station');
    }

    /**
     * Update a workflow step of the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateStep(int $facilityId, int $stepId, array $data): WorkflowStep
    {
        $step = WorkflowStep::query()
            ->whereHas('workflow.department', fn($q) => $q->where('facility_id', $facilityId))
            ->findOrFail($stepId);

        $step->update($data);

        return $step->load('station');
    }

    /**
     * Delete a workflow step of the facility.
     */
    public function deleteStep(int $facilityId, int $stepId): void
    {
        $step = WorkflowStep::query()
            ->whereHas('workflow.department', fn($q) => $q->where('facility_id', $facilityId))
            ->findOrFail($stepId);

        $step->delete();
    }

    /**
     * Ensure a department belongs to the facility.
     */
    private function assertDepartmentInFacility(int $facilityId, int $departmentId): void
    {
        Department::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($departmentId);
    }
}
