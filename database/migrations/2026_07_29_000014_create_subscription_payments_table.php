<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('plan_id');
            $table->unsignedBigInteger('user_subscription_id')->nullable();
            $table->string('razorpay_order_id')->unique();
            $table->string('razorpay_payment_id')->nullable()->unique();
            $table->text('razorpay_signature')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('created');
            $table->string('failure_code')->nullable();
            $table->text('failure_description')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
