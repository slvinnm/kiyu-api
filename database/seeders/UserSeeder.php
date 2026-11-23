<?php

namespace Database\Seeders;

use App\Enums\StationType;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed exactly one account per role.
     *
     * The platform operator has no tenant; every other role belongs to the demo
     * tenant. A receptionist is attached to the registration desk. The generic
     * staff account has no fixed station: real staff are assigned a station per
     * tenant when they are created.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'kiyu-demo')->firstOrFail();
        $receptionUnit = $this->receptionUnit();

        $accounts = [
            ['role' => UserRole::PlatformAdmin, 'email' => 'platform@kiyu.id', 'name' => 'Platform Operator', 'unit' => null],
            ['role' => UserRole::Admin, 'email' => 'admin@kiyu.id', 'name' => 'Administrator Kiyu', 'unit' => null],
            ['role' => UserRole::Receptionist, 'email' => 'reception@kiyu.id', 'name' => 'Alice Resepsionis', 'unit' => $receptionUnit],
            ['role' => UserRole::Staff, 'email' => 'staff@kiyu.id', 'name' => 'Sam Staff', 'unit' => null],
            ['role' => UserRole::Client, 'email' => 'client@kiyu.id', 'name' => 'Budi Santoso', 'unit' => null],
        ];

        foreach ($accounts as $account) {
            $role = $account['role'];

            $user = User::query()->firstOrNew(['email' => $account['email']]);

            if ($user->exists) {
                continue;
            }

            $user->fill([
                'name' => $account['name'],
                'password' => Hash::make('password'),
                'role' => $role,
            ]);

            $user->tenant_id = $role->isPlatform() ? null : $tenant->id;

            if ($account['unit'] !== null) {
                $user->department_id = $account['unit']['department_id'];
                $user->station_id = $account['unit']['station_id'];
            }

            $user->save();
        }
    }

    /**
     * The registration desk unit for the demo tenant, if configured.
     *
     * @return array{department_id: int, station_id: int}|null
     */
    private function receptionUnit(): ?array
    {
        $department = Department::query()->where('code', 'GEN')->first();

        if ($department === null) {
            return null;
        }

        $station = Station::query()
            ->where('department_id', $department->id)
            ->where('type', StationType::Registration->value)
            ->first();

        if ($station === null) {
            return null;
        }

        return [
            'department_id' => $station->department_id,
            'station_id' => $station->id,
        ];
    }
}
