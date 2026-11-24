<?php

namespace Database\Seeders;

use App\Enums\StationType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Station;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $facility = Facility::query()
            ->whereHas('tenant', fn($query) => $query->where('slug', 'kiyu-demo'))
            ->where('code', 'MAIN')
            ->firstOrFail();

        $generalDepartment = Department::updateOrCreate(
            ['code' => 'GEN'],
            [
                'facility_id' => $facility->id,
                'name' => 'General Service',
                'is_active' => true,
                'display_in_kiosk' => true,
            ]
        );

        $supportDepartment = Department::updateOrCreate(
            ['code' => 'SUP'],
            [
                'facility_id' => $facility->id,
                'name' => 'Support Service',
                'is_active' => true,
                'display_in_kiosk' => true,
            ]
        );

        $intakeStation = Station::firstOrCreate(
            ['code' => 'GEN-INT'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Intake Counter',
                'type' => StationType::Intake,
                'queue_prefix' => 'R',
                'is_active' => true,
            ]
        );

        $serviceStation = Station::firstOrCreate(
            ['code' => 'GEN-SVC1'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Service Counter 1',
                'type' => StationType::Service,
                'queue_prefix' => 'S1',
                'is_active' => true,
            ]
        );

        $secondServiceStation = Station::firstOrCreate(
            ['code' => 'GEN-SVC2'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Service Counter 2',
                'type' => StationType::Service,
                'queue_prefix' => 'S2',
                'is_active' => true,
            ]
        );

        Station::firstOrCreate(
            ['code' => 'GEN-CSH'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Cashier',
                'type' => StationType::Cashier,
                'queue_prefix' => 'K',
                'is_active' => true,
            ]
        );

        $supportIntakeStation = Station::firstOrCreate(
            ['code' => 'SUP-INT'],
            [
                'department_id' => $supportDepartment->id,
                'name' => 'Support Intake',
                'type' => StationType::Intake,
                'queue_prefix' => 'P',
                'is_active' => true,
            ]
        );

        $supportServiceStation = Station::firstOrCreate(
            ['code' => 'SUP-SVC'],
            [
                'department_id' => $supportDepartment->id,
                'name' => 'Support Counter',
                'type' => StationType::Service,
                'queue_prefix' => 'PS',
                'is_active' => true,
            ]
        );

        $generalWorkflow = Workflow::firstOrCreate(
            ['department_id' => $generalDepartment->id, 'name' => 'General Flow'],
            ['is_active' => true]
        );

        foreach ([
            ['station' => $intakeStation, 'sequence' => 1, 'name' => 'Intake'],
            ['station' => $serviceStation, 'sequence' => 2, 'name' => 'Service'],
            ['station' => $secondServiceStation, 'sequence' => 3, 'name' => 'Follow-up'],
        ] as $step) {
            WorkflowStep::firstOrCreate(
                [
                    'workflow_id' => $generalWorkflow->id,
                    'station_id' => $step['station']->id,
                    'sequence' => $step['sequence'],
                ],
                [
                    'name' => $step['name'],
                    'is_active' => true,
                    'is_optional' => false,
                    'can_skip' => false,
                ]
            );
        }

        $supportWorkflow = Workflow::firstOrCreate(
            ['department_id' => $supportDepartment->id, 'name' => 'Support Flow'],
            ['is_active' => true]
        );

        foreach ([
            ['station' => $supportIntakeStation, 'sequence' => 1, 'name' => 'Support Intake'],
            ['station' => $supportServiceStation, 'sequence' => 2, 'name' => 'Support Service'],
        ] as $step) {
            WorkflowStep::firstOrCreate(
                [
                    'workflow_id' => $supportWorkflow->id,
                    'station_id' => $step['station']->id,
                    'sequence' => $step['sequence'],
                ],
                [
                    'name' => $step['name'],
                    'is_active' => true,
                    'is_optional' => false,
                    'can_skip' => false,
                ]
            );
        }
    }
}
