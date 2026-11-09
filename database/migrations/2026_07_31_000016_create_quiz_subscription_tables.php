<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('quiz_count')->default(0);
            $table->unsignedInteger('days')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('offer_price', 12, 2)->nullable();
            $table->tinyInteger('status')->default(1);
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamps();
        });

        Schema::create('user_quiz_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->unsignedBigInteger('quiz_subscription_plan_id');
            $table->unsignedBigInteger('payment_id')->unique();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('quiz_count')->default(0);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->tinyInteger('payment_status')->default(1);
            $table->string('transaction')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['quiz_subscription_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_quiz_subscriptions');
        Schema::dropIfExists('quiz_subscription_plans');
    }
};
