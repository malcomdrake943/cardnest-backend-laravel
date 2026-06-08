<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('packages')->truncate();

        DB::table('packages')->insert([
            [
                'id' => 1,
                'package_name' => 'Standard',
                'package_price' => 30.00,
                'package_period' => '30 Days',
                'package_description' => 'Standard monthly subscription package',
                'monthly_limit' => 100,
                'overage_rate' => 0.30,
                'created_at' => '2025-06-19 16:10:12',
                'updated_at' => '2025-09-10 06:52:50',
            ],
            [
                'id' => 2,
                'package_name' => 'Premium',
                'package_price' => 62.50,
                'package_period' => '30 Days',
                'package_description' => 'Premium monthly subscription package',
                'monthly_limit' => 250,
                'overage_rate' => 0.25,
                'created_at' => '2025-06-19 16:11:01',
                'updated_at' => '2025-09-10 04:37:38',
            ],
            [
                'id' => 3,
                'package_name' => 'Enterprise',
                'package_price' => 30.00,
                'package_period' => '30 Days',
                'package_description' => null,
                'monthly_limit' => 100000000,
                'overage_rate' => 0.10,
                'created_at' => '2025-06-19 16:11:24',
                'updated_at' => '2025-06-19 16:11:24',
            ]
        ]);

        Schema::enableForeignKeyConstraints();
    }
}
