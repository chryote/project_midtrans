<?php

namespace Tests\Unit;

use App\Services\Midtrans\MidtransCoreApiService;
use Tests\TestCase;

class MidtransSignatureTest extends TestCase
{
    private MidtransCoreApiService $service;
    private string $serverKey = 'SB-Mid-server-test-key-12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['midtrans.server_key' => $this->serverKey]);
        $this->service = new MidtransCoreApiService();
    }

    public function test_signature_verification_succeeds_with_valid_hash(): void
    {
        $orderId = 'SUB-PRO-USER1-1700000000';
        $statusCode = '200';
        $grossAmount = '150000.00';

        $expectedHash = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);

        $result = $this->service->verifySignature($orderId, $statusCode, $grossAmount, $expectedHash);

        $this->assertTrue($result);
    }

    public function test_signature_verification_fails_with_tampered_amount(): void
    {
        $orderId = 'SUB-PRO-USER1-1700000000';
        $statusCode = '200';
        $originalAmount = '150000.00';
        $tamperedAmount = '1000.00';

        $validHash = hash('sha512', $orderId . $statusCode . $originalAmount . $this->serverKey);

        $result = $this->service->verifySignature($orderId, $statusCode, $tamperedAmount, $validHash);

        $this->assertFalse($result);
    }

    public function test_signature_verification_fails_with_invalid_signature(): void
    {
        $orderId = 'SUB-PRO-USER1-1700000000';
        $statusCode = '200';
        $grossAmount = '150000.00';

        $invalidHash = 'invalid-signature-hash-string';

        $result = $this->service->verifySignature($orderId, $statusCode, $grossAmount, $invalidHash);

        $this->assertFalse($result);
    }
}
