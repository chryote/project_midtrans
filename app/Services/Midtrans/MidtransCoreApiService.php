<?php

namespace App\Services\Midtrans;

use Exception;
use Midtrans\Config;
use Midtrans\CoreApi;
use Midtrans\Transaction;

class MidtransCoreApiService
{
    public function __construct()
    {
        $this->configure();
    }

    /**
     * Configure Midtrans client settings.
     */
    public function configure(): void
    {
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = (bool) config('midtrans.is_production', false);
        Config::$isSanitized = (bool) config('midtrans.is_sanitized', true);
        Config::$is3ds = (bool) config('midtrans.is_3ds', true);
    }

    /**
     * Charge Core API directly.
     *
     * @param  array  $payload
     * @return object
     * @throws Exception
     */
    public function charge(array $payload): object
    {
        $this->configure();
        return CoreApi::charge($payload);
    }

    /**
     * Create a Bank Transfer Virtual Account charge (BCA, BNI, BRI, Permata).
     *
     * @param  string  $orderId
     * @param  float   $grossAmount
     * @param  string  $bank
     * @param  string  $customerName
     * @param  string  $customerEmail
     * @return object
     * @throws Exception
     */
    public function chargeBankTransfer(
        string $orderId,
        float $grossAmount,
        string $bank,
        string $customerName,
        string $customerEmail
    ): object {
        $bank = strtolower($bank);

        $params = [
            'payment_type' => 'bank_transfer',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round($grossAmount),
            ],
            'customer_details' => [
                'first_name' => $customerName,
                'email' => $customerEmail,
            ],
        ];

        if ($bank === 'permata') {
            $params['bank_transfer'] = [
                'bank' => 'permata',
            ];
        } elseif ($bank === 'mandiri') {
            $params['payment_type'] = 'echannel';
            $params['echannel'] = [
                'bill_info1' => 'Payment For:',
                'bill_info2' => 'SaaS Subscription',
            ];
        } else {
            // bca, bni, bri
            $params['bank_transfer'] = [
                'bank' => $bank,
            ];
        }

        return $this->charge($params);
    }

    /**
     * Create a QRIS charge (GoPay, ShopeePay, Dana, LinkAja, Mobile Banking).
     *
     * @param  string  $orderId
     * @param  float   $grossAmount
     * @param  string  $customerName
     * @param  string  $customerEmail
     * @return object
     * @throws Exception
     */
    public function chargeQris(
        string $orderId,
        float $grossAmount,
        string $customerName,
        string $customerEmail
    ): object {
        $params = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round($grossAmount),
            ],
            'qris' => [
                'acquirer' => 'gopay',
            ],
            'customer_details' => [
                'first_name' => $customerName,
                'email' => $customerEmail,
            ],
        ];

        return $this->charge($params);
    }

    /**
     * Verify the cryptographic SHA-512 signature from Midtrans webhook notification.
     *
     * Formula: SHA512(order_id + status_code + gross_amount + ServerKey)
     *
     * @param  string  $orderId
     * @param  string  $statusCode
     * @param  string  $grossAmount
     * @param  string  $receivedSignature
     * @return bool
     */
    public function verifySignature(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $receivedSignature
    ): bool {
        $serverKey = config('midtrans.server_key');
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * Fetch status directly from Midtrans API.
     *
     * @param  string  $orderId
     * @return object
     */
    public function getTransactionStatus(string $orderId): object
    {
        $this->configure();
        return Transaction::status($orderId);
    }
}
