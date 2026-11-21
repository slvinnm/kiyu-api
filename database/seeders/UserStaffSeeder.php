<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserStaffSeeder extends Seeder
{
    public function run(): void
    {
        // Departments
        $generalDepartment = Department::where('code', 'GEN')->first();
        $dentalDepartment = Department::where('code', 'DNT')->first();

        // General Department Stations
        $generalRegistrationStation = Station::where('code', 'GEN-REG')->first();
        $generalNurseStation = Station::where('code', 'GEN-NUR')->first();
        $generalDoctorStation = Station::where('code', 'GEN-DOC1')->first();
        $generalSecondDoctorStation = Station::where('code', 'GEN-DOC2')->first();
        $generalPharmacyStation = Station::where('code', 'GEN-PHA')->first();

        // Dental Department Stations
        $dentalRegistrationStation = Station::where('code', 'DNT-REG')->first();
        $dentalDoctorStation = Station::where('code', 'DNT-DOC')->first();

        // Super Admin
        User::firstOrCreate(
            ['email' => 'admin@kiyu.id'],
            [
                'name' => 'Administrator Kiyu',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin->value,
            ]
        );

        // General Department Staff
        $generalStaff = [
            [
                'email' => 'general.reception@kiyu.id',
                'name' => 'Alice Resepsionis Poli Umum',
                'department_id' => $generalDepartment?->id,
                'station_id' => $generalRegistrationStation?->id,
                'role' => UserRole::Receptionist,
            ],
            [
                'email' => 'nurse@kiyu.id',
                'name' => 'Bob Perawat',
                'department_id' => $generalDepartment?->id,
                'station_id' => $generalNurseStation?->id,
                'role' => UserRole::Nurse,
            ],
            [
                'email' => 'doctor1@kiyu.id',
                'name' => 'dr. Charlie Sp.PD',
                'department_id' => $generalDepartment?->id,
                'station_id' => $generalDoctorStation?->id,
                'role' => UserRole::Doctor,
            ],
            [
                'email' => 'doctor2@kiyu.id',
                'name' => 'dr. Diana',
                'department_id' => $generalDepartment?->id,
                'station_id' => $generalSecondDoctorStation?->id,
                'role' => UserRole::Doctor,
            ],
            [
                'email' => 'pharmacy@kiyu.id',
                'name' => 'Edward S.Farm, Apt',
                'department_id' => $generalDepartment?->id,
                'station_id' => $generalPharmacyStation?->id,
                'role' => UserRole::Pharmacist,
            ],
        ];

        foreach ($generalStaff as $staff) {
            User::firstOrCreate(
                ['email' => $staff['email']],
                [
                    'name' => $staff['name'],
                    'password' => Hash::make('password'),
                    'department_id' => $staff['department_id'],
                    'station_id' => $staff['station_id'],
                    'role' => $staff['role']->value,
                ]
            );
        }

        // Dental Department Staff
        $dentalStaff = [
            [
                'email' => 'dental.reception@kiyu.id',
                'name' => 'Rina Resepsionis Gigi',
                'department_id' => $dentalDepartment?->id,
                'station_id' => $dentalRegistrationStation?->id,
                'role' => UserRole::Receptionist,
            ],
            [
                'email' => 'dentist@kiyu.id',
                'name' => 'drg. Fajar',
                'department_id' => $dentalDepartment?->id,
                'station_id' => $dentalDoctorStation?->id,
                'role' => UserRole::Doctor,
            ],
        ];

        foreach ($dentalStaff as $staff) {
            User::firstOrCreate(
                ['email' => $staff['email']],
                [
                    'name' => $staff['name'],
                    'password' => Hash::make('password'),
                    'department_id' => $staff['department_id'],
                    'station_id' => $staff['station_id'],
                    'role' => $staff['role']->value,
                ]
            );
        }
    }
}
