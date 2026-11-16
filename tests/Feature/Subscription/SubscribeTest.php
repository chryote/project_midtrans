<?php

namespace Tests\Feature\Subscription;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Midtrans\MidtransCoreApiService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SubscribeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'John Subscriber',
            'email' => 'john.sub@example.com',
        ]);

        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000.00,
            'currency' => 'IDR',
            'billing_interval' => 'month',
            'is_active' => true,
        ]);
    }

    public function test_authenticated_user_can_subscribe_with_bank_transfer_va(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // Mock Midtrans Core API response
        $mockCoreApi = Mockery::mock(MidtransCoreApiService::class);
        $mockCoreApi->shouldReceive('chargeBankTransfer')
            ->once()
            ->andReturn((object) [
                'status_code' => '201',
                'status_message' => 'Success, Bank Transfer transaction is created',
                'transaction_id' => 'midtrans-trx-uuid-1234',
                'order_id' => 'SUB-PRO-PLAN-1',
                'gross_amount' => '150000.00',
                'payment_type' => 'bank_transfer',
                'transaction_time' => '2026-11-16 00:00:00',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    (object) [
                        'bank' => 'bca',
                        'va_number' => '12345678901',
                    ],
                ],
            ]);

        $this->app->instance(MidtransCoreApiService::class, $mockCoreApi);

        $payload = [
            'plan_slug' => 'pro-plan',
            'payment_type' => 'bank_transfer',
            'bank' => 'bca',
        ];

        $response = $this->postJson(route('api.v1.subscriptions.subscribe'), $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'code',
                'message',
                'data' => [
                    'subscription_id',
                    'status',
                    'order_id',
                    'gross_amount',
                    'currency',
                    'payment_type',
                    'payment_instructions' => [
                        'bank',
                        'va_number',
                    ],
                ],
            ])
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'pending',
                    'gross_amount' => 150000.00,
                    'payment_instructions' => [
                        'bank' => 'bca',
                        'va_number' => '12345678901',
                    ],
                ],
            ]);

        // Assert Subscription & Invoice in Database
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('invoices', [
            'user_id' => $this->user->id,
            'status' => Invoice::STATUS_PENDING,
            'gross_amount' => 150000.00,
            'payment_type' => 'bank_transfer',
        ]);
    }

    public function test_authenticated_user_can_subscribe_with_qris(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $mockCoreApi = Mockery::mock(MidtransCoreApiService::class);
        $mockCoreApi->shouldReceive('chargeQris')
            ->once()
            ->andReturn((object) [
                'status_code' => '201',
                'status_message' => 'Success, QRIS transaction is created',
                'transaction_id' => 'midtrans-qris-uuid-5678',
                'order_id' => 'SUB-PRO-QRIS-2',
                'gross_amount' => '150000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'actions' => [
                    (object) [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/5678/qr-code',
                    ],
                ],
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118936009110022345678...',
            ]);

        $this->app->instance(MidtransCoreApiService::class, $mockCoreApi);

        $payload = [
            'plan_slug' => 'pro-plan',
            'payment_type' => 'qris',
        ];

        $response = $this->postJson(route('api.v1.subscriptions.subscribe'), $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'payment_type' => 'qris',
                    'payment_instructions' => [
                        'qr_url' => 'https://api.sandbox.midtrans.com/v2/qris/5678/qr-code',
                    ],
                ],
            ]);
    }

    public function test_acid_rollback_when_midtrans_charge_fails(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $mockCoreApi = Mockery::mock(MidtransCoreApiService::class);
        $mockCoreApi->shouldReceive('chargeBankTransfer')
            ->once()
            ->andThrow(new Exception('Midtrans Bank API Connection Timeout'));

        $this->app->instance(MidtransCoreApiService::class, $mockCoreApi);

        $payload = [
            'plan_slug' => 'pro-plan',
            'payment_type' => 'bank_transfer',
            'bank' => 'bca',
        ];

        $response = $this->postJson(route('api.v1.subscriptions.subscribe'), $payload);

        $response->assertStatus(500)
            ->assertJson([
                'status' => 'error',
            ]);

        // Zero orphan subscriptions or invoices must exist
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('invoices', 0);
    }
}
