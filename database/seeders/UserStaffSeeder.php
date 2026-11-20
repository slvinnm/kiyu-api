<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserStaffSeeder extends Seeder
{
    public function run(): void
    {
        $genDept = Department::where('code', 'GEN')->first();
        $dentalDept = Department::where('code', 'DNT')->first();

        $regStation = Station::where('code', 'GEN-REG')->first();
        $nurseStation = Station::where('code', 'GEN-NUR')->first();
        $doc1Station = Station::where('code', 'GEN-DOC1')->first();
        $doc2Station = Station::where('code', 'GEN-DOC2')->first();
        $phaStation = Station::where('code', 'GEN-PHA')->first();

        // 1. Super Admin
        User::firstOrCreate(
            ['email' => 'admin@kiyu.id'],
            [
                'name' => 'Administrator Kiyu',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        // 2. Receptionist (Alice)
        User::firstOrCreate(
            ['email' => 'reception@kiyu.id'],
            [
                'name' => 'Alice Resepsionis',
                'password' => Hash::make('password'),
                'department_id' => $genDept?->id,
                'station_id' => $regStation?->id,
                'role' => 'receptionist',
            ]
        );

        // 3. Nurse (Bob)
        User::firstOrCreate(
            ['email' => 'nurse@kiyu.id'],
            [
                'name' => 'Bob Perawat',
                'password' => Hash::make('password'),
                'department_id' => $genDept?->id,
                'station_id' => $nurseStation?->id,
                'role' => 'nurse',
            ]
        );

        // 4. Doctor 1 (Dr. Charlie)
        User::firstOrCreate(
            ['email' => 'doctor1@kiyu.id'],
            [
                'name' => 'dr. Charlie Sp.PD',
                'password' => Hash::make('password'),
                'department_id' => $genDept?->id,
                'station_id' => $doc1Station?->id,
                'role' => 'doctor',
            ]
        );

        // 5. Doctor 2 (Dr. Diana)
        User::firstOrCreate(
            ['email' => 'doctor2@kiyu.id'],
            [
                'name' => 'dr. Diana',
                'password' => Hash::make('password'),
                'department_id' => $genDept?->id,
                'station_id' => $doc2Station?->id,
                'role' => 'doctor',
            ]
        );

        // 6. Pharmacist (Edward) — Poli Umum
        User::firstOrCreate(
            ['email' => 'pharmacy@kiyu.id'],
            [
                'name' => 'Edward S.Farm, Apt',
                'password' => Hash::make('password'),
                'department_id' => $genDept?->id,
                'station_id' => $phaStation?->id,
                'role' => 'pharmacist',
            ]
        );

        $dentalReg = Station::where('code', 'DNT-REG')->first();
        $dentalDoc = Station::where('code', 'DNT-DOC')->first();
        $cardio = Department::where('code', 'CAR')->first();
        $cardioReg = Station::where('code', 'CAR-REG')->first();
        $cardioDoc = Station::where('code', 'CAR-DOC')->first();
        $apotek = Department::where('code', 'APT')->first();
        $aptReg = Station::where('code', 'APT-REG')->first();
        $aptPha = Station::where('code', 'APT-PHA')->first();
        $laboratorium = Department::where('code', 'LAB')->first();
        $labReg = Station::where('code', 'LAB-REG')->first();
        $labStation = Station::where('code', 'LAB-LAB')->first();
        $kasir = Department::where('code', 'BILL')->first();
        $billReg = Station::where('code', 'BILL-REG')->first();
        $billCash = Station::where('code', 'BILL-CASH')->first();

        // 7. Dental staff
        User::firstOrCreate(
            ['email' => 'dental.reception@kiyu.id'],
            [
                'name' => 'Rina Resepsionis Gigi',
                'password' => Hash::make('password'),
                'department_id' => $dentalDept?->id,
                'station_id' => $dentalReg?->id,
                'role' => 'receptionist',
            ]
        );

        User::firstOrCreate(
            ['email' => 'dentist@kiyu.id'],
            [
                'name' => 'drg. Fajar',
                'password' => Hash::make('password'),
                'department_id' => $dentalDept?->id,
                'station_id' => $dentalDoc?->id,
                'role' => 'doctor',
            ]
        );

        // 8. Cardiology staff
        User::firstOrCreate(
            ['email' => 'cardio.reception@kiyu.id'],
            [
                'name' => 'Dewi Resepsionis Jantung',
                'password' => Hash::make('password'),
                'department_id' => $cardio?->id,
                'station_id' => $cardioReg?->id,
                'role' => 'receptionist',
            ]
        );

        User::firstOrCreate(
            ['email' => 'cardiologist@kiyu.id'],
            [
                'name' => 'dr. Gunawan Sp.JP',
                'password' => Hash::make('password'),
                'department_id' => $cardio?->id,
                'station_id' => $cardioDoc?->id,
                'role' => 'doctor',
            ]
        );

        // 9. Apotek staff (department tersendiri)
        User::firstOrCreate(
            ['email' => 'apt.reception@kiyu.id'],
            [
                'name' => 'Hana Resepsionis Apotek',
                'password' => Hash::make('password'),
                'department_id' => $apotek?->id,
                'station_id' => $aptReg?->id,
                'role' => 'receptionist',
            ]
        );

        User::firstOrCreate(
            ['email' => 'apoteker@kiyu.id'],
            [
                'name' => 'Indah S.Farm, Apt',
                'password' => Hash::make('password'),
                'department_id' => $apotek?->id,
                'station_id' => $aptPha?->id,
                'role' => 'pharmacist',
            ]
        );

        // 10. Laboratorium staff
        User::firstOrCreate(
            ['email' => 'lab.reception@kiyu.id'],
            [
                'name' => 'Joko Resepsionis Lab',
                'password' => Hash::make('password'),
                'department_id' => $laboratorium?->id,
                'station_id' => $labReg?->id,
                'role' => 'receptionist',
            ]
        );

        User::firstOrCreate(
            ['email' => 'lab@kiyu.id'],
            [
                'name' => 'Kartika A.Md.AK',
                'password' => Hash::make('password'),
                'department_id' => $laboratorium?->id,
                'station_id' => $labStation?->id,
                'role' => 'lab',
            ]
        );

        // 11. Kasir (Billing) staff
        User::firstOrCreate(
            ['email' => 'kasir.reception@kiyu.id'],
            [
                'name' => 'Lukman Resepsionis Kasir',
                'password' => Hash::make('password'),
                'department_id' => $kasir?->id,
                'station_id' => $billReg?->id,
                'role' => 'receptionist',
            ]
        );

        User::firstOrCreate(
            ['email' => 'cashier@kiyu.id'],
            [
                'name' => 'Maya Kasir',
                'password' => Hash::make('password'),
                'department_id' => $kasir?->id,
                'station_id' => $billCash?->id,
                'role' => 'billing',
            ]
        );
    }
}
