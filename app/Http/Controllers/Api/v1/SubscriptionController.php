<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\SubscribeRequest;
use App\Models\Plan;
use App\Services\Subscription\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * Get all active subscription plans.
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->get();

        return $this->successResponse($plans, 'Active plans retrieved successfully');
    }

    /**
     * Subscribe to a plan using Midtrans Core API (VT-Direct).
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $plan = null;
        if (! empty($validated['plan_id'])) {
            $plan = Plan::find($validated['plan_id']);
        } elseif (! empty($validated['plan_slug'])) {
            $plan = Plan::where('slug', $validated['plan_slug'])->first();
        } else {
            // Default to Pro Plan if none specified
            $plan = Plan::where('slug', 'pro-plan')->first();
        }

        if (! $plan || ! $plan->is_active) {
            return $this->errorResponse('Selected plan is invalid or inactive', 404);
        }

        $paymentType = $validated['payment_type'];
        $bank = $validated['bank'] ?? 'bca';

        try {
            $result = $this->subscriptionService->subscribe(
                user: $user,
                plan: $plan,
                paymentType: $paymentType,
                bank: $bank
            );

            return $this->successResponse([
                'subscription_id' => $result['subscription']->id,
                'status' => $result['subscription']->status,
                'order_id' => $result['invoice']->order_id,
                'gross_amount' => $result['invoice']->gross_amount,
                'currency' => $result['invoice']->currency,
                'payment_type' => $result['invoice']->payment_type,
                'payment_instructions' => $result['payment'],
            ], 'Subscription initiated successfully. Please complete payment using the instructions provided.', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to initiate subscription charge: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get current user's subscription and invoice history.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        $activeSubscription = $user->subscriptions()
            ->with('plan')
            ->whereIn('status', ['active', 'pending'])
            ->latest()
            ->first();

        $invoices = $user->invoices()
            ->latest()
            ->limit(5)
            ->get();

        return $this->successResponse([
            'is_premium' => $activeSubscription ? $activeSubscription->isActive() : false,
            'subscription' => $activeSubscription,
            'recent_invoices' => $invoices,
        ], 'Current subscription status retrieved');
    }
}
