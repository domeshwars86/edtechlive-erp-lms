<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razorpay_payment_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fee_payment_id')->nullable()->unique()->constrained('fee_payments')->nullOnDelete();
            $table->string('receipt', 40)->unique();
            $table->string('gateway_order_id', 80)->nullable()->unique();
            $table->string('gateway_payment_id', 80)->nullable()->unique();
            $table->string('idempotency_key', 128);
            $table->unsignedBigInteger('amount_paise');
            $table->string('currency', 3)->default('INR');
            $table->string('status', 24)->default('creating');
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'payer_user_id', 'idempotency_key'], 'razorpay_orders_idempotency_unique');
            $table->index(['organization_id', 'fee_record_id', 'status'], 'razorpay_orders_fee_status_index');
        });

        Schema::create('razorpay_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 120)->unique();
            $table->string('event_type', 80);
            $table->char('body_sha256', 64);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_webhook_events');
        Schema::dropIfExists('razorpay_payment_orders');
    }
};
