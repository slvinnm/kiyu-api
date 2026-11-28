<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with master data only.
     */
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            MasterDataSeeder::class,
            UserSeeder::class,
            BillingSeeder::class,
            FeatureSeeder::class,
            AddonSeeder::class,
        ]);
    }
}
