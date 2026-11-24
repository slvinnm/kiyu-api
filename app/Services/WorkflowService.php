<?php

namespace App\Services;

use App\Enums\StationType;
use App\Exceptions\InvalidWorkflowException;
use App\Models\Department;
use App\Models\Workflow;
use App\Models\WorkflowStep;

class WorkflowService
{
    /**
     * Resolve the active primary workflow for a department.
     *
     * @throws InvalidWorkflowException
     */
    public function resolvePrimaryWorkflow(int $departmentId): Workflow
    {
        $department = Department::query()->find($departmentId);
        if (! $department || ! $department->is_active) {
            throw new InvalidWorkflowException("Active department #{$departmentId} not found.");
        }

        /** @var Workflow|null $workflow */
        $workflow = Workflow::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $workflow) {
            throw new InvalidWorkflowException("No active workflow configured for department #{$departmentId}.");
        }

        return $workflow;
    }

    /**
     * Resolve the active registration step for a workflow.
     *
     * @throws InvalidWorkflowException
     */
    public function resolveRegistrationStep(Workflow $workflow): WorkflowStep
    {
        $registrationSteps = WorkflowStep::query()
            ->with('station')
            ->where('workflow_id', $workflow->id)
            ->where('is_active', true)
            ->whereHas('station', function ($query) {
                $query->where('type', StationType::Intake->value)
                    ->where('is_active', true);
            })
            ->get();

        if ($registrationSteps->isEmpty()) {
            throw new InvalidWorkflowException("Workflow #{$workflow->id} has no active registration step.");
        }

        if ($registrationSteps->count() > 1) {
            throw new InvalidWorkflowException("Workflow #{$workflow->id} has multiple active registration steps.");
        }

        return $registrationSteps->first();
    }

    /**
     * Resolve the next active workflow step after the given sequence.
     *
     * Optional steps are still offered as a ticket, because staff may choose
     * to serve them; a visit is not blocked on them. See
     * {@see hasPendingRequiredSteps()} for that side.
     */
    public function resolveNextStep(Workflow $workflow, int $currentSequence): ?WorkflowStep
    {
        return WorkflowStep::query()
            ->with('station')
            ->where('workflow_id', $workflow->id)
            ->where('is_active', true)
            ->where('sequence', '>', $currentSequence)
            ->whereHas('station', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('sequence', 'asc')
            ->first();
    }

    /**
     * Whether a required (non-optional) step is still pending after this one.
     *
     * Used when a ticket is skipped: an optional step can be skipped without
     * closing the visit, but jumping over the last required step ends the
     * workflow.
     */
    public function hasPendingRequiredSteps(Workflow $workflow, int $currentSequence): bool
    {
        return WorkflowStep::query()
            ->where('workflow_id', $workflow->id)
            ->where('is_active', true)
            ->where('is_optional', false)
            ->where('sequence', '>', $currentSequence)
            ->whereHas('station', fn($query) => $query->where('is_active', true))
            ->exists();
    }
}
