<?php

namespace Database\Seeders;

use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\QueueAcquisitionStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Patient;
use App\Models\QueueAcquisition;
use App\Models\QueueCounter;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Station;
use App\Models\Visit;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Operational queues for departments that are units of their own
 * (Apotek, Laboratorium, Kasir). Kept idempotent so `db:seed` can be re-run.
 */
class DepartmentQueueSeeder extends Seeder
{
    public function run(): void
    {
        $patients = Patient::limit(10)->get();

        if ($patients->count() < 10) {
            return;
        }

        $this->seedDepartmentQueue('APT', 'APT-PHA', 'A001', 'AP001', 'apoteker@kiyu.id', $patients[8], $patients[9], '0012', '0013');
        $this->seedDepartmentQueue('LAB', 'LAB-LAB', 'L001', 'LB001', 'lab@kiyu.id', $patients[8], $patients[9], '0014', '0015');
        $this->seedDepartmentQueue('BILL', 'BILL-CASH', 'K001', 'KS001', 'cashier@kiyu.id', $patients[8], $patients[9], '0016', '0017');
    }

    private function seedDepartmentQueue(
        string $departmentCode,
        string $serviceStationCode,
        string $registrationQueueNumber,
        string $serviceQueueNumber,
        string $serviceUser,
        Patient $registrationPatient,
        Patient $servicePatient,
        string $registrationVisitSuffix,
        string $serviceVisitSuffix
    ): void {
        $department = Department::where('code', $departmentCode)->first();

        if (! $department) {
            return;
        }

        $workflow = Workflow::where('department_id', $department->id)->first();

        if (! $workflow) {
            return;
        }

        $regStation = Station::where('code', "{$departmentCode}-REG")->first();
        $serviceStation = Station::where('code', $serviceStationCode)->first();

        if (! $regStation || ! $serviceStation) {
            return;
        }

        $regStep = WorkflowStep::where('workflow_id', $workflow->id)
            ->where('station_id', $regStation->id)
            ->first();
        $serviceStep = WorkflowStep::where('workflow_id', $workflow->id)
            ->where('station_id', $serviceStation->id)
            ->first();

        if (! $regStep || ! $serviceStep) {
            return;
        }

        $date = Carbon::today()->format('Ymd');
        $counterDate = Carbon::today()->format('Y-m-d');

        QueueCounter::updateOrCreate(
            ['station_id' => $regStation->id, 'counter_date' => $counterDate],
            ['last_sequence' => max(1, QueueCounter::where('station_id', $regStation->id)->where('counter_date', $counterDate)->value('last_sequence') ?? 1)]
        );

        QueueCounter::updateOrCreate(
            ['station_id' => $serviceStation->id, 'counter_date' => $counterDate],
            ['last_sequence' => max(1, QueueCounter::where('station_id', $serviceStation->id)->where('counter_date', $counterDate)->value('last_sequence') ?? 1)]
        );

        $this->seedVisit(
            visitNumber: "V-{$date}-{$registrationVisitSuffix}",
            patient: $registrationPatient,
            department: $department,
            workflow: $workflow,
            step: $regStep,
            station: $regStation,
            queueNumber: $registrationQueueNumber,
            status: QueueTicketStatus::Created,
            assignedTo: null,
            visitStatus: VisitStatus::Waiting,
            checkedInAt: now()->subMinutes(12),
            calledAt: null,
            startedAt: null
        );

        $this->seedVisit(
            visitNumber: "V-{$date}-{$serviceVisitSuffix}",
            patient: $servicePatient,
            department: $department,
            workflow: $workflow,
            step: $serviceStep,
            station: $serviceStation,
            queueNumber: $serviceQueueNumber,
            status: QueueTicketStatus::InProgress,
            assignedTo: $serviceUser,
            visitStatus: VisitStatus::InProgress,
            checkedInAt: now()->subMinutes(45),
            calledAt: now()->subMinutes(10),
            startedAt: now()->subMinutes(8)
        );
    }

    private function seedVisit(
        string $visitNumber,
        Patient $patient,
        Department $department,
        Workflow $workflow,
        WorkflowStep $step,
        Station $station,
        string $queueNumber,
        QueueTicketStatus $status,
        ?string $assignedTo,
        VisitStatus $visitStatus,
        Carbon $checkedInAt,
        ?Carbon $calledAt,
        ?Carbon $startedAt
    ): void {
        $visit = Visit::firstOrCreate(
            ['visit_number' => $visitNumber],
            [
                'visit_token' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'department_id' => $department->id,
                'workflow_id' => $workflow->id,
                'priority' => Priority::Normal,
                'intake_channel' => IntakeChannel::Kiosk,
                'status' => $visitStatus,
                'checked_in_at' => $checkedInAt,
                'registered_at' => $visitStatus === VisitStatus::InProgress ? now()->subMinutes(35) : null,
            ]
        );

        QueueAcquisition::firstOrCreate(
            ['visit_id' => $visit->id, 'channel' => IntakeChannel::Kiosk],
            [
                'department_id' => $department->id,
                'status' => QueueAcquisitionStatus::Acquired,
            ]
        );

        $ticket = QueueTicket::firstOrCreate(
            [
                'visit_id' => $visit->id,
                'workflow_step_id' => $step->id,
                'station_id' => $station->id,
            ],
            [
                'queue_number' => $queueNumber,
                'priority' => Priority::Normal,
                'internal_sequence' => 1,
                'status' => $status,
                'called_at' => $calledAt,
                'started_at' => $startedAt,
                'assigned_to' => $assignedTo,
            ]
        );

        if ($ticket->wasRecentlyCreated) {
            QueueEvent::create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => $status === QueueTicketStatus::InProgress
                    ? QueueEventType::Started
                    : QueueEventType::Created,
                'from_status' => $status === QueueTicketStatus::InProgress
                    ? QueueTicketStatus::Called->value
                    : null,
                'to_status' => $status->value,
                'user_id' => $assignedTo,
            ]);
        }
    }
}
