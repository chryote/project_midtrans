<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::firstOrCreate(
            ['slug' => 'pro-plan'],
            [
                'name' => 'Pro Plan',
                'description' => 'Unlimited access to all premium SaaS features, priority API access, and automated receipt billing.',
                'price' => 150000.00,
                'currency' => 'IDR',
                'billing_interval' => 'month',
                'is_active' => true,
            ]
        );

        Plan::firstOrCreate(
            ['slug' => 'enterprise-plan'],
            [
                'name' => 'Enterprise Plan',
                'description' => 'Dedicated infrastructure, custom SLAs, unlimited team seats, and 24/7 dedicated support.',
                'price' => 500000.00,
                'currency' => 'IDR',
                'billing_interval' => 'month',
                'is_active' => true,
            ]
        );
    }
}
