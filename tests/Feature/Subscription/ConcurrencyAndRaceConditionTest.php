<?php

namespace Tests\Feature\Subscription;

use App\Jobs\SendSubscriptionReceiptJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConcurrencyAndRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Plan $plan;
    private Subscription $subscription;
    private Invoice $invoice;
    private string $serverKey = 'SB-Mid-server-test-key-12345';
    private string $orderId = 'SUB-PRO-RACE-CONCURRENCY-1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['midtrans.server_key' => $this->serverKey]);

        $this->user = User::factory()->create();

        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000.00,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->subscription = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $this->invoice = Invoice::create([
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->user->id,
            'order_id' => $this->orderId,
            'gross_amount' => 150000.00,
            'currency' => 'IDR',
            'payment_type' => 'bank_transfer',
            'status' => Invoice::STATUS_PENDING,
        ]);
    }

    public function test_duplicate_webhook_delivery_is_idempotent_and_does_not_duplicate_receipts(): void
    {
        Queue::fake();

        $statusCode = '200';
        $grossAmount = '150000.00';
        $signature = hash('sha512', $this->orderId . $statusCode . $grossAmount . $this->serverKey);

        $payload = [
            'order_id' => $this->orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-trx-repeat',
            'payment_type' => 'bank_transfer',
            'signature_key' => $signature,
        ];

        // 1. First Webhook Request
        $res1 = $this->postJson(route('api.v1.midtrans.webhook'), $payload);
        $res1->assertStatus(200);

        $initialPeriodEnd = $this->subscription->fresh()->current_period_ends_at;

        // 2. Second Webhook Request (Duplicate / Retry from Midtrans)
        $res2 = $this->postJson(route('api.v1.midtrans.webhook'), $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Webhook already processed (Idempotent).',
            ]);

        // 3. Third Webhook Request
        $res3 = $this->postJson(route('api.v1.midtrans.webhook'), $payload);
        $res3->assertStatus(200);

        // Assert Period End did NOT get bumped multiple times
        $this->assertEquals($initialPeriodEnd->toDateTimeString(), $this->subscription->fresh()->current_period_ends_at->toDateTimeString());

        // Assert SendSubscriptionReceiptJob was pushed ONLY ONCE despite 3 identical requests
        Queue::assertPushed(SendSubscriptionReceiptJob::class, 1);

        // Assert Webhook Events logged 1 processed and 2 ignored
        $this->assertEquals(1, WebhookEvent::where('order_id', $this->orderId)->where('status', 'processed')->count());
        $this->assertEquals(2, WebhookEvent::where('order_id', $this->orderId)->where('status', 'ignored')->count());
    }

    public function test_race_condition_protection_under_multiple_simulated_threads(): void
    {
        Queue::fake();

        $statusCode = '200';
        $grossAmount = '150000.00';
        $signature = hash('sha512', $this->orderId . $statusCode . $grossAmount . $this->serverKey);

        $payload = [
            'order_id' => $this->orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-trx-concurrent',
            'signature_key' => $signature,
        ];

        // Simulate 5 rapid sequential and interleaved requests for the same order_id
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson(route('api.v1.midtrans.webhook'), $payload);
        }

        // All requests must return 200 OK (no 500 errors to Midtrans)
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // Exactly one job pushed
        Queue::assertPushed(SendSubscriptionReceiptJob::class, 1);

        // Subscription remains active and invoice paid
        $this->assertEquals(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
        $this->assertEquals(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
    }
}
