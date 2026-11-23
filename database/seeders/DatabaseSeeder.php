<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with master data only.
     *
     * No operational flow is seeded: no visits, tickets, queues or referrals.
     * Use the application or the tenant onboarding flow to exercise runtime
     * behaviour.
     */
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            MasterDataSeeder::class,
            UserSeeder::class,
            BillingSeeder::class,
        ]);
    }
}
