<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\Subscription\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * Handle incoming payment notification webhook from Midtrans.
     *
     * Protected by cryptographic SHA-512 signature validation,
     * distributed locking, and ACID pessimistic database locks.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $result = $this->subscriptionService->processWebhook($payload);

        $httpCode = $result['status'] ?? 200;

        if (! empty($result['success'])) {
            return $this->successResponse($result, $result['message'], $httpCode);
        }

        return $this->errorResponse($result['message'], $httpCode, $result);
    }
}
