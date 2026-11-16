<?php

namespace Tests\Feature\Subscription;

use App\Jobs\SendSubscriptionReceiptJob;
use App\Mail\SubscriptionReceiptMail;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReceiptJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_job_sends_email_to_customer_for_paid_invoice(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'subscriber@company.com',
        ]);

        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000.00,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $invoice = Invoice::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'order_id' => 'SUB-RECEIPT-TEST-1',
            'gross_amount' => 150000.00,
            'payment_type' => 'bank_transfer',
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $job = new SendSubscriptionReceiptJob($invoice->id);
        $job->handle();

        Mail::assertSent(SubscriptionReceiptMail::class, function ($mail) use ($user, $invoice) {
            return $mail->hasTo($user->email) &&
                   $mail->invoice->id === $invoice->id;
        });
    }

    public function test_receipt_job_skips_unpaid_invoices(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'price' => 150000.00,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $invoice = Invoice::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'order_id' => 'SUB-UNPAID-TEST-2',
            'gross_amount' => 150000.00,
            'payment_type' => 'bank_transfer',
            'status' => Invoice::STATUS_PENDING,
        ]);

        $job = new SendSubscriptionReceiptJob($invoice->id);
        $job->handle();

        Mail::assertNothingSent();
    }
}
