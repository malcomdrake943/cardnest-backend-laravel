<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionsSeeder extends Seeder
{
    /**
     * Seeder for the `subscriptions` table.
     * Generated on 2026-06-09 11:41:54 from current_subscriptions.csv
     * Total rows : 5
     * Chunk size : 500
     */
    public function run(): void
    {
        DB::table('subscriptions')->insert([
            [
                'id' => 47,
                'user_id' => '150',
                'merchant_id' => 'mer000150',
                'is_custom_renewal' => true,
                'package_id' => 1,
                'api_call_limit' => '1272',
                'api_calls_used' => '0',
                'overage_calls' => '0',
                'status' => 'active',
                'subscription_date' => '2026-06-04',
                'renewal_date' => '2026-07-04',
                'created_at' => '2025-08-15 10:46:13',
                'updated_at' => '2026-06-04 00:00:01',
            ],
            [
                'id' => 60,
                'user_id' => '173',
                'merchant_id' => 'G5536942984B2978',
                'is_custom_renewal' => false,
                'package_id' => 1,
                'api_call_limit' => '450',
                'api_calls_used' => '9',
                'overage_calls' => '0',
                'status' => 'not-active',
                'subscription_date' => '2025-09-10',
                'renewal_date' => '2025-10-10',
                'created_at' => '2025-09-02 04:25:15',
                'updated_at' => '2026-05-21 11:45:16',
            ],
            [
                'id' => 61,
                'user_id' => '182',
                'merchant_id' => '93500624K6V95758',
                'is_custom_renewal' => true,
                'package_id' => 3,
                'api_call_limit' => '2913',
                'api_calls_used' => '9',
                'overage_calls' => '0',
                'status' => 'active',
                'subscription_date' => '2026-05-17',
                'renewal_date' => '2026-06-17',
                'created_at' => '2025-09-06 03:41:30',
                'updated_at' => '2026-05-31 16:43:00',
            ],
            [
                'id' => 64,
                'user_id' => '209',
                'merchant_id' => '4845925323992C4M',
                'is_custom_renewal' => false,
                'package_id' => 3,
                'api_call_limit' => '200000097',
                'api_calls_used' => '0',
                'overage_calls' => '0',
                'status' => 'not-active',
                'subscription_date' => '2026-03-13',
                'renewal_date' => '2026-04-13',
                'created_at' => '2026-01-07 08:05:37',
                'updated_at' => '2026-03-13 22:03:35',
            ],
            [
                'id' => 65,
                'user_id' => '206',
                'merchant_id' => '6O4584Y217268387',
                'is_custom_renewal' => true,
                'package_id' => 3,
                'api_call_limit' => '5998',
                'api_calls_used' => '39',
                'overage_calls' => '0',
                'status' => 'active',
                'subscription_date' => '2026-05-20',
                'renewal_date' => '2026-06-20',
                'created_at' => '2026-02-12 20:50:36',
                'updated_at' => '2026-06-09 10:30:14',
            ],
        ]);
    }
}
