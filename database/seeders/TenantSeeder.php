<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\KioskDevice;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'kiyu-demo'],
            [
                'name' => 'KIYU Demo Organization',
                'status' => TenantStatus::Active,
                'contact_email' => 'admin@kiyu.id',
                'timezone' => 'Asia/Jakarta',
                'allow_public_registration' => true,
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

        $rawToken = 'kiyu-demo-kiosk-token';

        KioskDevice::query()->firstOrCreate(
            ['credential_hash' => hash('sha256', $rawToken)],
            [
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'name' => 'Demo Kiosk',
                'is_active' => true,
                'paired_at' => now(),
            ]
        );
    }
}
