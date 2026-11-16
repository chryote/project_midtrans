<?php

namespace Tests\Feature\Subscription;

use App\Jobs\SendSubscriptionReceiptJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Plan $plan;
    private Subscription $subscription;
    private Invoice $invoice;
    private string $serverKey = 'SB-Mid-server-test-key-12345';
    private string $orderId = 'SUB-PRO-USER1-TEST-1001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['midtrans.server_key' => $this->serverKey]);

        $this->user = User::factory()->create([
            'email' => 'webhook.user@example.com',
        ]);

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

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = [
            'order_id' => $this->orderId,
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'invalid-fake-signature',
        ];

        $response = $this->postJson(route('api.v1.midtrans.webhook'), $payload);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid signature.',
            ]);

        // Ensure database state remains untouched
        $this->assertEquals(Subscription::STATUS_PENDING, $this->subscription->fresh()->status);
        $this->assertEquals(Invoice::STATUS_PENDING, $this->invoice->fresh()->status);
    }

    public function test_webhook_settlement_activates_subscription_and_queues_receipt_job(): void
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
            'transaction_id' => 'midtrans-trx-99999',
            'payment_type' => 'bank_transfer',
            'signature_key' => $signature,
        ];

        $response = $this->postJson(route('api.v1.midtrans.webhook'), $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => $this->orderId,
                ],
            ]);

        // 1. Verify Invoice is marked Paid
        $refreshedInvoice = $this->invoice->fresh();
        $this->assertEquals(Invoice::STATUS_PAID, $refreshedInvoice->status);
        $this->assertNotNull($refreshedInvoice->paid_at);
        $this->assertEquals('midtrans-trx-99999', $refreshedInvoice->transaction_id);

        // 2. Verify Subscription is activated
        $refreshedSub = $this->subscription->fresh();
        $this->assertEquals(Subscription::STATUS_ACTIVE, $refreshedSub->status);
        $this->assertNotNull($refreshedSub->current_period_starts_at);
        $this->assertNotNull($refreshedSub->current_period_ends_at);
        $this->assertTrue($refreshedSub->isActive());

        // 3. Verify Webhook Audit Log
        $this->assertDatabaseHas('webhook_events', [
            'order_id' => $this->orderId,
            'event_type' => 'settlement',
            'status' => 'processed',
        ]);

        // 4. Verify Receipt Email Job was Dispatched
        Queue::assertPushed(SendSubscriptionReceiptJob::class, function ($job) {
            return $job->invoiceId === $this->invoice->id;
        });
    }

    public function test_webhook_expire_marks_subscription_and_invoice_expired(): void
    {
        Queue::fake();

        $statusCode = '202';
        $grossAmount = '150000.00';
        $signature = hash('sha512', $this->orderId . $statusCode . $grossAmount . $this->serverKey);

        $payload = [
            'order_id' => $this->orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_status' => 'expire',
            'signature_key' => $signature,
        ];

        $response = $this->postJson(route('api.v1.midtrans.webhook'), $payload);

        $response->assertStatus(200);

        $this->assertEquals(Invoice::STATUS_EXPIRED, $this->invoice->fresh()->status);
        $this->assertEquals(Subscription::STATUS_EXPIRED, $this->subscription->fresh()->status);

        Queue::assertNotPushed(SendSubscriptionReceiptJob::class);
    }
}
