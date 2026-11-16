<?php

namespace App\Services\Subscription;

use App\Jobs\SendSubscriptionReceiptJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Midtrans\MidtransCoreApiService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(
        protected MidtransCoreApiService $midtransService
    ) {}

    /**
     * Subscribe a user to a plan using Midtrans Core API (VT-Direct).
     *
     * Fully ACID-compliant: creates pending subscription and invoice atomically.
     *
     * @param  User    $user
     * @param  Plan    $plan
     * @param  string  $paymentType ('bank_transfer' or 'qris')
     * @param  string  $bank ('bca', 'bni', 'bri', 'permata', 'mandiri')
     * @return array
     * @throws Exception
     */
    public function subscribe(User $user, Plan $plan, string $paymentType = 'bank_transfer', string $bank = 'bca'): array
    {
        $orderId = 'SUB-' . strtoupper($plan->slug) . '-' . $user->id . '-' . time() . '-' . Str::random(4);

        // Execute Midtrans Core API charge first
        if ($paymentType === 'qris') {
            $chargeResponse = $this->midtransService->chargeQris(
                orderId: $orderId,
                grossAmount: (float) $plan->price,
                customerName: $user->name,
                customerEmail: $user->email
            );
        } else {
            $chargeResponse = $this->midtransService->chargeBankTransfer(
                orderId: $orderId,
                grossAmount: (float) $plan->price,
                bank: $bank,
                customerName: $user->name,
                customerEmail: $user->email
            );
        }

        // Parse payment details from Midtrans response
        $paymentDetails = $this->extractPaymentDetails($chargeResponse, $paymentType, $bank);

        // ACID Transaction to persist subscription and pending invoice
        return DB::transaction(function () use ($user, $plan, $orderId, $paymentType, $paymentDetails, $chargeResponse) {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_PENDING,
            ]);

            $invoice = Invoice::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'order_id' => $orderId,
                'transaction_id' => $chargeResponse->transaction_id ?? null,
                'gross_amount' => $plan->price,
                'currency' => $plan->currency ?? 'IDR',
                'payment_type' => $paymentType,
                'payment_details' => $paymentDetails,
                'status' => Invoice::STATUS_PENDING,
            ]);

            return [
                'subscription' => $subscription,
                'invoice' => $invoice,
                'payment' => $paymentDetails,
            ];
        });
    }

    /**
     * Process incoming Midtrans Webhook with strict ACID compliance,
     * distributed locking, and pessimistic row locking to prevent race conditions.
     *
     * @param  array  $payload
     * @return array
     * @throws Exception
     */
    public function processWebhook(array $payload): array
    {
        $orderId = $payload['order_id'] ?? null;
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $receivedSignature = $payload['signature_key'] ?? '';
        $transactionStatus = $payload['transaction_status'] ?? '';
        $fraudStatus = $payload['fraud_status'] ?? 'accept';
        $transactionId = $payload['transaction_id'] ?? null;

        if (! $orderId || ! $statusCode || ! $grossAmount || ! $receivedSignature) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Malformed webhook payload. Missing required verification fields.',
            ];
        }

        // 1. Cryptographic SHA-512 Signature Verification
        if (! $this->midtransService->verifySignature($orderId, $statusCode, $grossAmount, $receivedSignature)) {
            Log::warning("Midtrans Webhook: Invalid SHA-512 signature for order_id: {$orderId}");
            return [
                'success' => false,
                'status' => 403,
                'message' => 'Invalid signature.',
            ];
        }

        // 2. Distributed Lock via Redis to prevent concurrent webhook execution
        $lockKey = "midtrans_webhook_lock:{$orderId}";
        $lockTimeout = (int) config('midtrans.webhook_lock_timeout', 10);
        $lock = Cache::lock($lockKey, $lockTimeout);

        try {
            // Block up to 5 seconds to acquire lock
            return $lock->block(5, function () use ($orderId, $transactionStatus, $fraudStatus, $transactionId, $payload) {

                // 3. ACID Transaction with Pessimistic Row Locking (lockForUpdate)
                return DB::transaction(function () use ($orderId, $transactionStatus, $fraudStatus, $transactionId, $payload) {

                    /** @var Invoice|null $invoice */
                    $invoice = Invoice::where('order_id', $orderId)
                        ->lockForUpdate()
                        ->first();

                    if (! $invoice) {
                        Log::warning("Midtrans Webhook: Invoice not found for order_id: {$orderId}");
                        return [
                            'success' => false,
                            'status' => 404,
                            'message' => 'Order not found.',
                        ];
                    }

                    // 4. Idempotency Check: Already processed?
                    if ($invoice->isPaid() && in_array($transactionStatus, ['settlement', 'capture'])) {
                        Log::info("Midtrans Webhook: Order {$orderId} already paid. Skipping redundant processing (Idempotent).");

                        WebhookEvent::create([
                            'order_id' => $orderId,
                            'transaction_id' => $transactionId,
                            'event_type' => $transactionStatus,
                            'payload' => $payload,
                            'status' => 'ignored',
                        ]);

                        return [
                            'success' => true,
                            'status' => 200,
                            'message' => 'Webhook already processed (Idempotent).',
                            'order_id' => $orderId,
                        ];
                    }

                    /** @var Subscription $subscription */
                    $subscription = Subscription::where('id', $invoice->subscription_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $eventStatus = 'processed';

                    // 5. State Machine Transitions
                    if ($transactionStatus === 'settlement' || ($transactionStatus === 'capture' && $fraudStatus === 'accept')) {
                        // Mark Invoice Paid
                        $invoice->update([
                            'status' => Invoice::STATUS_PAID,
                            'paid_at' => now(),
                            'transaction_id' => $transactionId ?? $invoice->transaction_id,
                        ]);

                        // Activate Subscription for 1 month
                        $startsAt = now();
                        $endsAt = now()->addMonth();

                        $subscription->update([
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_starts_at' => $startsAt,
                            'current_period_ends_at' => $endsAt,
                        ]);

                        // 6. Asynchronous Receipt Email Dispatch (after commit to avoid race conditions)
                        SendSubscriptionReceiptJob::dispatch($invoice->id)->afterCommit();

                        Log::info("Midtrans Webhook: Subscription activated and invoice marked paid for order: {$orderId}");

                    } elseif ($transactionStatus === 'expire') {
                        $invoice->update(['status' => Invoice::STATUS_EXPIRED]);
                        $subscription->update(['status' => Subscription::STATUS_EXPIRED]);
                        Log::info("Midtrans Webhook: Order {$orderId} expired.");

                    } elseif (in_array($transactionStatus, ['cancel', 'deny'])) {
                        $invoice->update(['status' => Invoice::STATUS_FAILED]);
                        $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
                        Log::info("Midtrans Webhook: Order {$orderId} {$transactionStatus}.");

                    } elseif ($transactionStatus === 'pending') {
                        Log::info("Midtrans Webhook: Order {$orderId} is awaiting customer payment.");
                    }

                    // Audit Log Event
                    WebhookEvent::create([
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'event_type' => $transactionStatus,
                        'payload' => $payload,
                        'status' => $eventStatus,
                    ]);

                    return [
                        'success' => true,
                        'status' => 200,
                        'message' => "Order {$orderId} processed with status {$transactionStatus}.",
                        'order_id' => $orderId,
                    ];
                });
            });
        } catch (\Throwable $e) {
            Log::error("Midtrans Webhook Error for order {$orderId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Internal processing error: ' . $e->getMessage(),
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Extract convenient payment details from Midtrans response object.
     *
     * @param  object  $response
     * @param  string  $paymentType
     * @param  string  $bank
     * @return array
     */
    protected function extractPaymentDetails(object $response, string $paymentType, string $bank): array
    {
        $details = [
            'payment_type' => $paymentType,
            'order_id' => $response->order_id ?? null,
            'gross_amount' => $response->gross_amount ?? null,
            'transaction_status' => $response->transaction_status ?? 'pending',
            'transaction_time' => $response->transaction_time ?? null,
        ];

        if ($paymentType === 'qris') {
            $details['qr_url'] = $response->actions[0]->url ?? null;
            $details['qr_string'] = $response->qr_string ?? null;
            return $details;
        }

        // Bank transfer details
        if (! empty($response->va_numbers)) {
            $details['bank'] = $response->va_numbers[0]->bank ?? $bank;
            $details['va_number'] = $response->va_numbers[0]->va_number ?? null;
        } elseif (! empty($response->permata_va_number)) {
            $details['bank'] = 'permata';
            $details['va_number'] = $response->permata_va_number;
        } elseif (! empty($response->bill_key)) {
            $details['bank'] = 'mandiri';
            $details['bill_key'] = $response->bill_key;
            $details['biller_code'] = $response->biller_code;
        }

        return $details;
    }
}
