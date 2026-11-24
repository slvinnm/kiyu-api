<?php

namespace Database\Seeders;

use App\Enums\StationType;
use App\Enums\UserRole;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'kiyu-demo')->firstOrFail();
        $facility = $tenant->facilities()->where('code', 'MAIN')->firstOrFail();
        $intakeStation = Station::query()
            ->where('type', StationType::Intake->value)
            ->first();

        $accounts = [
            ['role' => UserRole::PlatformAdmin, 'email' => 'platform@kiyu.id', 'name' => 'Platform Operator', 'station' => null],
            ['role' => UserRole::TenantAdmin, 'email' => 'admin@kiyu.id', 'name' => 'Tenant Administrator', 'station' => null],
            ['role' => UserRole::FacilityAdmin, 'email' => 'facility@kiyu.id', 'name' => 'Facility Administrator', 'station' => null],
            ['role' => UserRole::Operator, 'email' => 'operator@kiyu.id', 'name' => 'Front Desk Operator', 'station' => $intakeStation],
            ['role' => UserRole::Staff, 'email' => 'staff@kiyu.id', 'name' => 'Service Staff', 'station' => null],
            ['role' => UserRole::Client, 'email' => 'client@kiyu.id', 'name' => 'Sample Client', 'station' => null],
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
            $user->station_id = $account['station']?->id;
            $user->save();

            if ($role->isFacilityScoped()) {
                UserFacilityAssignment::query()->firstOrCreate([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'facility_id' => $facility->id,
                ]);
            }
        }

        $owner = User::query()->where('email', 'admin@kiyu.id')->first();
        if ($owner !== null) {
            $tenant->forceFill(['owner_user_id' => $owner->id])->save();
        }
    }
}
