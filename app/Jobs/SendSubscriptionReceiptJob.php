<?php

namespace App\Jobs;

use App\Mail\SubscriptionReceiptMail;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $invoiceId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $invoice = Invoice::with(['user', 'subscription.plan'])->find($this->invoiceId);

        if (! $invoice) {
            Log::warning("SendSubscriptionReceiptJob: Invoice ID {$this->invoiceId} not found.");
            return;
        }

        if (! $invoice->isPaid()) {
            Log::warning("SendSubscriptionReceiptJob: Invoice ID {$this->invoiceId} is not paid. Skipping receipt.");
            return;
        }

        if (! $invoice->user || ! $invoice->user->email) {
            Log::warning("SendSubscriptionReceiptJob: Invoice ID {$this->invoiceId} has no recipient email.");
            return;
        }

        try {
            Mail::to($invoice->user->email)->send(new SubscriptionReceiptMail($invoice));
            Log::info("Subscription receipt sent successfully for Invoice #{$invoice->order_id} to {$invoice->user->email}");
        } catch (\Throwable $e) {
            Log::error("Failed sending subscription receipt for Invoice #{$invoice->order_id}: " . $e->getMessage());
            throw $e;
        }
    }
}
