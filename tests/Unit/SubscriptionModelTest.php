<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_active_returns_true_when_active_and_period_is_future(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->assertTrue($sub->isActive());
    }

    public function test_is_active_returns_false_when_period_is_expired(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_starts_at' => now()->subMonths(2),
            'current_period_ends_at' => now()->subMonth(),
        ]);

        $this->assertFalse($sub->isActive());
    }

    public function test_is_pending_returns_true_for_pending_subscription(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $this->assertTrue($sub->isPending());
        $this->assertFalse($sub->isActive());
    }
}
