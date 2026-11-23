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
        // All master data is owned by the demo tenant's facility. The
        // TenantSeeder runs first and must have created it.
        $facility = Facility::query()
            ->whereHas('tenant', fn($query) => $query->where('slug', 'kiyu-demo'))
            ->where('code', 'MAIN')
            ->firstOrFail();

        // Departments
        $generalDepartment = Department::updateOrCreate(
            ['code' => 'GEN'],
            [
                'facility_id' => $facility->id,
                'name' => 'Poli Umum',
                'is_active' => true,
                'display_in_kiosk' => true,
            ]
        );

        $dentalDepartment = Department::updateOrCreate(
            ['code' => 'DNT'],
            [
                'facility_id' => $facility->id,
                'name' => 'Poli Gigi',
                'is_active' => true,
                'display_in_kiosk' => true,
            ]
        );

        // General Department Stations
        $generalRegistrationStation = Station::firstOrCreate(
            ['code' => 'GEN-REG'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Loket Pendaftaran Umum',
                'type' => StationType::Registration,
                'queue_prefix' => 'R',
                'is_active' => true,
            ]
        );

        $generalNurseStation = Station::firstOrCreate(
            ['code' => 'GEN-NUR'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Ruang Perawat Umum',
                'type' => StationType::Nurse,
                'queue_prefix' => 'N',
                'is_active' => true,
            ]
        );

        $generalDoctorStation = Station::firstOrCreate(
            ['code' => 'GEN-DOC1'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Ruang Dokter 1',
                'type' => StationType::Doctor,
                'queue_prefix' => 'D1',
                'is_active' => true,
            ]
        );

        $generalPharmacyStation = Station::firstOrCreate(
            ['code' => 'GEN-PHA'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Apotek',
                'type' => StationType::Pharmacy,
                'queue_prefix' => 'P',
                'is_active' => true,
            ]
        );

        Station::firstOrCreate(
            ['code' => 'GEN-LAB'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Laboratorium',
                'type' => StationType::Laboratory,
                'queue_prefix' => 'L',
                'is_active' => true,
            ]
        );

        Station::firstOrCreate(
            ['code' => 'GEN-BIL'],
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Kasir',
                'type' => StationType::Billing,
                'queue_prefix' => 'K',
                'is_active' => true,
            ]
        );

        // Dental Department Stations
        $dentalRegistrationStation = Station::firstOrCreate(
            ['code' => 'DNT-REG'],
            [
                'department_id' => $dentalDepartment->id,
                'name' => 'Loket Pendaftaran Gigi',
                'type' => StationType::Registration,
                'queue_prefix' => 'RG',
                'is_active' => true,
            ]
        );

        $dentalDoctorStation = Station::firstOrCreate(
            ['code' => 'DNT-DOC'],
            [
                'department_id' => $dentalDepartment->id,
                'name' => 'Ruang Dokter Gigi',
                'type' => StationType::Doctor,
                'queue_prefix' => 'DG',
                'is_active' => true,
            ]
        );

        // General Department Workflow
        $generalWorkflow = Workflow::firstOrCreate(
            [
                'department_id' => $generalDepartment->id,
                'name' => 'Workflow Umum',
            ],
            ['is_active' => true]
        );

        $generalWorkflowSteps = [
            [
                'station' => $generalRegistrationStation,
                'sequence' => 1,
                'name' => 'Registrasi',
            ],
            [
                'station' => $generalNurseStation,
                'sequence' => 2,
                'name' => 'Perawatan',
            ],
            [
                'station' => $generalDoctorStation,
                'sequence' => 3,
                'name' => 'Pemeriksaan Dokter',
            ],
            [
                'station' => $generalPharmacyStation,
                'sequence' => 4,
                'name' => 'Apotek',
            ],
        ];

        foreach ($generalWorkflowSteps as $step) {
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
                    'is_repeatable' => false,
                    'can_skip' => false,
                    'completion_requirements' => null,
                    'entry_conditions' => null,
                ]
            );
        }

        // Dental Department Workflow
        $dentalWorkflow = Workflow::firstOrCreate(
            [
                'department_id' => $dentalDepartment->id,
                'name' => 'Workflow Gigi',
            ],
            ['is_active' => true]
        );

        $dentalWorkflowSteps = [
            [
                'station' => $dentalRegistrationStation,
                'sequence' => 1,
                'name' => 'Registrasi Gigi',
            ],
            [
                'station' => $dentalDoctorStation,
                'sequence' => 2,
                'name' => 'Pemeriksaan Gigi',
            ],
        ];

        foreach ($dentalWorkflowSteps as $step) {
            WorkflowStep::firstOrCreate(
                [
                    'workflow_id' => $dentalWorkflow->id,
                    'station_id' => $step['station']->id,
                    'sequence' => $step['sequence'],
                ],
                [
                    'name' => $step['name'],
                    'is_active' => true,
                    'is_optional' => false,
                    'is_repeatable' => false,
                    'can_skip' => false,
                    'completion_requirements' => null,
                    'entry_conditions' => null,
                ]
            );
        }
    }
}
