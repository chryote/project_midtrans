<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Receipt for your Subscription - Invoice #' . $this->invoice->order_id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.receipt',
            with: [
                'invoice' => $this->invoice,
                'user' => $this->invoice->user,
                'subscription' => $this->invoice->subscription,
                'plan' => $this->invoice->subscription->plan,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
