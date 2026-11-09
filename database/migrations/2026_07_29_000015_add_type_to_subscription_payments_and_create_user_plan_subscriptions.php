<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->string('subscription_type', 30)->default('role')->after('plan_id');
            $table->unsignedBigInteger('user_plan_subscription_id')->nullable()->after('user_subscription_id');
            $table->index(['subscription_type', 'plan_id']);
        });

        Schema::create('user_plan_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('plan_id');
            $table->string('subscription_type', 30);
            $table->unsignedBigInteger('payment_id')->unique();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('credits')->default(0);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->tinyInteger('payment_status')->default(1);
            $table->string('transaction')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'subscription_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_plan_subscriptions');
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropIndex(['subscription_type', 'plan_id']);
            $table->dropColumn(['subscription_type', 'user_plan_subscription_id']);
        });
    }
};
