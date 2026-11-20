<?php

namespace Database\Seeders;

use App\Enums\StationType;
use App\Models\Department;
use App\Models\Station;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $general = Department::firstOrCreate(
            ['code' => 'GEN'],
            ['name' => 'Poli Umum', 'is_active' => true]
        );

        $dental = Department::firstOrCreate(
            ['code' => 'DNT'],
            ['name' => 'Poli Gigi', 'is_active' => true]
        );

        $cardio = Department::firstOrCreate(
            ['code' => 'CAR'],
            ['name' => 'Jantung', 'is_active' => true]
        );

        $apotek = Department::firstOrCreate(
            ['code' => 'APT'],
            ['name' => 'Apotek', 'is_active' => true]
        );

        $laboratorium = Department::firstOrCreate(
            ['code' => 'LAB'],
            ['name' => 'Laboratorium', 'is_active' => true]
        );

        $kasir = Department::firstOrCreate(
            ['code' => 'BILL'],
            ['name' => 'Kasir', 'is_active' => true]
        );

        $registrasiGeneral = Station::firstOrCreate(
            ['code' => 'GEN-REG'],
            [
                'department_id' => $general->id,
                'name' => 'Loket Pendaftaran Umum',
                'type' => StationType::Registration,
                'queue_prefix' => 'R',
                'is_active' => true,
            ]
        );

        $nurseGeneral = Station::firstOrCreate(
            ['code' => 'GEN-NUR'],
            [
                'department_id' => $general->id,
                'name' => 'Ruang Perawat Umum',
                'type' => StationType::Nurse,
                'queue_prefix' => 'N',
                'is_active' => true,
            ]
        );

        $doctor1 = Station::firstOrCreate(
            ['code' => 'GEN-DOC1'],
            [
                'department_id' => $general->id,
                'name' => 'Ruang Dokter 1',
                'type' => StationType::Doctor,
                'queue_prefix' => 'D1',
                'is_active' => true,
            ]
        );

        $doctor2 = Station::firstOrCreate(
            ['code' => 'GEN-DOC2'],
            [
                'department_id' => $general->id,
                'name' => 'Ruang Dokter 2',
                'type' => StationType::Doctor,
                'queue_prefix' => 'D2',
                'is_active' => true,
            ]
        );

        $pharmacy = Station::firstOrCreate(
            ['code' => 'GEN-PHA'],
            [
                'department_id' => $general->id,
                'name' => 'Apotek',
                'type' => StationType::Pharmacy,
                'queue_prefix' => 'P',
                'is_active' => true,
            ]
        );

        // Dental
        Station::firstOrCreate(
            ['code' => 'DNT-REG'],
            [
                'department_id' => $dental->id,
                'name' => 'Loket Pendaftaran Gigi',
                'type' => StationType::Registration,
                'queue_prefix' => 'RG',
                'is_active' => true,
            ]
        );

        Station::firstOrCreate(
            ['code' => 'DNT-DOC'],
            [
                'department_id' => $dental->id,
                'name' => 'Ruang Dokter Gigi',
                'type' => StationType::Doctor,
                'queue_prefix' => 'DG',
                'is_active' => true,
            ]
        );

        // Cardio
        Station::firstOrCreate(
            ['code' => 'CAR-REG'],
            [
                'department_id' => $cardio->id,
                'name' => 'Loket Pendaftaran Jantung',
                'type' => StationType::Registration,
                'queue_prefix' => 'CR',
                'is_active' => true,
            ]
        );

        Station::firstOrCreate(
            ['code' => 'CAR-DOC'],
            [
                'department_id' => $cardio->id,
                'name' => 'Ruang Dokter Jantung',
                'type' => StationType::Doctor,
                'queue_prefix' => 'CD',
                'is_active' => true,
            ]
        );

        // Apotek (unit tersendiri, bukan hanya station di Poli Umum)
        $aptReg = Station::firstOrCreate(
            ['code' => 'APT-REG'],
            [
                'department_id' => $apotek->id,
                'name' => 'Loket Pendaftaran Apotek',
                'type' => StationType::Registration,
                'queue_prefix' => 'A',
                'is_active' => true,
            ]
        );

        $aptPha = Station::firstOrCreate(
            ['code' => 'APT-PHA'],
            [
                'department_id' => $apotek->id,
                'name' => 'Penyerahan Obat',
                'type' => StationType::Pharmacy,
                'queue_prefix' => 'AP',
                'is_active' => true,
            ]
        );

        // Laboratorium
        $labReg = Station::firstOrCreate(
            ['code' => 'LAB-REG'],
            [
                'department_id' => $laboratorium->id,
                'name' => 'Loket Pendaftaran Laboratorium',
                'type' => StationType::Registration,
                'queue_prefix' => 'L',
                'is_active' => true,
            ]
        );

        $labLab = Station::firstOrCreate(
            ['code' => 'LAB-LAB'],
            [
                'department_id' => $laboratorium->id,
                'name' => 'Ruang Pemeriksaan Laboratorium',
                'type' => StationType::Laboratory,
                'queue_prefix' => 'LB',
                'is_active' => true,
            ]
        );

        // Kasir (Billing)
        $billReg = Station::firstOrCreate(
            ['code' => 'BILL-REG'],
            [
                'department_id' => $kasir->id,
                'name' => 'Loket Pendaftaran Kasir',
                'type' => StationType::Registration,
                'queue_prefix' => 'K',
                'is_active' => true,
            ]
        );

        $billCash = Station::firstOrCreate(
            ['code' => 'BILL-CASH'],
            [
                'department_id' => $kasir->id,
                'name' => 'Meja Pembayaran',
                'type' => StationType::Billing,
                'queue_prefix' => 'KS',
                'is_active' => true,
            ]
        );

        // Workflow General
        $genWorkflow = Workflow::firstOrCreate(
            ['department_id' => $general->id, 'name' => 'Workflow Umum'],
            ['is_active' => true]
        );

        WorkflowStep::firstOrCreate(
            ['workflow_id' => $genWorkflow->id, 'station_id' => $registrasiGeneral->id, 'sequence' => 1],
            ['name' => 'Registrasi', 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
        );

        WorkflowStep::firstOrCreate(
            ['workflow_id' => $genWorkflow->id, 'station_id' => $nurseGeneral->id, 'sequence' => 2],
            ['name' => 'Perawatan', 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
        );

        WorkflowStep::firstOrCreate(
            ['workflow_id' => $genWorkflow->id, 'station_id' => $doctor1->id, 'sequence' => 3],
            ['name' => 'Pemeriksaan Dokter', 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
        );

        WorkflowStep::firstOrCreate(
            ['workflow_id' => $genWorkflow->id, 'station_id' => $pharmacy->id, 'sequence' => 4],
            ['name' => 'Apotek', 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
        );

        // Workflow Dental
        $dentalWorkflow = Workflow::firstOrCreate(
            ['department_id' => $dental->id, 'name' => 'Workflow Gigi'],
            ['is_active' => true]
        );

        $dentalReg = Station::where('code', 'DNT-REG')->first();
        $dentalDoc = Station::where('code', 'DNT-DOC')->first();

        if ($dentalReg && $dentalDoc) {
            WorkflowStep::firstOrCreate(
                ['workflow_id' => $dentalWorkflow->id, 'station_id' => $dentalReg->id, 'sequence' => 1],
                ['name' => 'Registrasi Gigi', 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
            );
            WorkflowStep::firstOrCreate(
                ['workflow_id' => $dentalWorkflow->id, 'station_id' => $dentalDoc->id, 'sequence' => 2],
                ['name' => 'Pemeriksaan Gigi', 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
            );
        }

        // Workflow Cardio (melengkapi Poli Jantung yang sebelumnya tanpa alur)
        $carReg = Station::where('code', 'CAR-REG')->first();
        $carDoc = Station::where('code', 'CAR-DOC')->first();

        if ($carReg && $carDoc) {
            $carWorkflow = Workflow::firstOrCreate(
                ['department_id' => $cardio->id, 'name' => 'Workflow Jantung'],
                ['is_active' => true]
            );

            $this->addStep($carWorkflow, $carReg, 1, 'Registrasi Jantung');
            $this->addStep($carWorkflow, $carDoc, 2, 'Pemeriksaan Jantung');
        }

        // Workflow Apotek
        $aptWorkflow = Workflow::firstOrCreate(
            ['department_id' => $apotek->id, 'name' => 'Workflow Apotek'],
            ['is_active' => true]
        );

        $this->addStep($aptWorkflow, $aptReg, 1, 'Registrasi Apotek');
        $this->addStep($aptWorkflow, $aptPha, 2, 'Penyerahan Obat');

        // Workflow Laboratorium
        $labWorkflow = Workflow::firstOrCreate(
            ['department_id' => $laboratorium->id, 'name' => 'Workflow Laboratorium'],
            ['is_active' => true]
        );

        $this->addStep($labWorkflow, $labReg, 1, 'Registrasi Laboratorium');
        $this->addStep($labWorkflow, $labLab, 2, 'Pemeriksaan Laboratorium');

        // Workflow Kasir
        $billWorkflow = Workflow::firstOrCreate(
            ['department_id' => $kasir->id, 'name' => 'Workflow Kasir'],
            ['is_active' => true]
        );

        $this->addStep($billWorkflow, $billReg, 1, 'Registrasi Kasir');
        $this->addStep($billWorkflow, $billCash, 2, 'Pembayaran');
    }

    private function addStep(Workflow $workflow, Station $station, int $sequence, string $name): void
    {
        WorkflowStep::firstOrCreate(
            ['workflow_id' => $workflow->id, 'station_id' => $station->id, 'sequence' => $sequence],
            ['name' => $name, 'is_active' => true, 'is_optional' => false, 'is_repeatable' => false, 'can_skip' => false, 'completion_requirements' => null, 'entry_conditions' => null]
        );
    }
}
