<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Subscription Plans Table
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('billing_interval')->default('month'); // month, year
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // 2. Subscriptions Table
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, active, past_due, cancelled, expired
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        // 3. Invoices / Orders Table
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('order_id')->unique();
            $table->string('transaction_id')->nullable()->index();
            $table->decimal('gross_amount', 14, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_type'); // bank_transfer, qris, gopay, etc.
            $table->json('payment_details')->nullable();
            $table->string('status')->default('pending'); // pending, paid, failed, expired
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['subscription_id', 'status']);
        });

        // 4. Webhook Events Audit & Idempotency Table
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->string('transaction_id')->nullable()->index();
            $table->string('event_type'); // settlement, pending, expire, deny, cancel
            $table->json('payload');
            $table->string('status')->default('processed'); // processed, ignored, failed
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            $table->index(['order_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
