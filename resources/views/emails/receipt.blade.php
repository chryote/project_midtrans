<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f7fafc; padding: 20px; color: #2d3748; }
        .receipt-card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 32px; }
        .header { border-bottom: 2px solid #edf2f7; padding-bottom: 20px; margin-bottom: 24px; }
        .title { font-size: 24px; font-weight: bold; color: #1a202c; }
        .details-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .details-table th, .details-table td { padding: 12px; text-align: left; border-bottom: 1px solid #edf2f7; }
        .total-row { font-weight: bold; font-size: 18px; color: #2b6cb0; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; background: #c6f6d5; color: #22543d; }
    </style>
</head>
<body>
    <div class="receipt-card">
        <div class="header">
            <span class="badge">PAID</span>
            <h1 class="title">Payment Receipt</h1>
            <p>Invoice <strong>#{{ $invoice->order_id }}</strong></p>
            <p>Date: {{ optional($invoice->paid_at)->format('d M Y, H:i') ?? now()->format('d M Y, H:i') }}</p>
        </div>

        <p>Hi {{ $user->name }},</p>
        <p>Thank you for subscribing to <strong>{{ $plan->name }}</strong>! Your payment has been received and your premium account is active.</p>

        <table class="details-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Period</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $plan->name }} (Monthly Subscription)</td>
                    <td>
                        {{ optional($subscription->current_period_starts_at)->format('d M Y') }} -
                        {{ optional($subscription->current_period_ends_at)->format('d M Y') }}
                    </td>
                    <td style="text-align: right;">Rp {{ number_format($invoice->gross_amount, 0, ',', '.') }}</td>
                </tr>
                <tr class="total-row">
                    <td colspan="2">Total Paid</td>
                    <td style="text-align: right;">Rp {{ number_format($invoice->gross_amount, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        <p style="margin-top: 24px; font-size: 13px; color: #718096;">
            Payment Method: {{ strtoupper($invoice->payment_type) }} | Transaction ID: {{ $invoice->transaction_id ?? 'N/A' }}
        </p>
    </div>
</body>
</html>
