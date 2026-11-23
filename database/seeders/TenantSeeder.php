<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\KioskDevice;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Provision the demo tenant and its single facility.
     *
     * This is a development/demo seeder only. Production clinics are onboarded
     * through the tenant onboarding flow, never through DatabaseSeeder.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'kiyu-demo'],
            [
                'name' => 'KIYU Demo Clinic',
                'status' => TenantStatus::Active,
                'contact_email' => 'admin@kiyu.id',
                'timezone' => 'Asia/Jakarta',
            ]
        );

        $facility = Facility::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'MAIN'],
            [
                'name' => 'KIYU Demo Facility',
                'address' => 'Jl. Sudirman No. 1, Jakarta',
                'is_active' => true,
            ]
        );

        // A demo kiosk device. The raw token is fixed for local development so
        // it can be set in the frontend env; production provisions a random
        // token shown once.
        $rawToken = 'kiyu-demo-kiosk-token';

        KioskDevice::query()->firstOrCreate(
            ['credential_hash' => hash('sha256', $rawToken)],
            [
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'name' => 'Demo Kiosk',
                'is_active' => true,
            ]
        );
    }
}
