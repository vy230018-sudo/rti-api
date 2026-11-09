<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->decimal('percentage', 5, 2);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referral_id');
            $table->unsignedBigInteger('subscription_payment_id')->nullable();
            $table->string('user_id');
            $table->string('from_user_id')->nullable();
            $table->string('referred_user_id');
            $table->unsignedTinyInteger('level');
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamps();
            $table->unique(['subscription_payment_id', 'user_id'], 'referral_commissions_payment_user_unique');
            $table->index(['user_id', 'status']);
            $table->index(['referred_user_id', 'level']);
        });

        foreach ([1 => 20, 2 => 10, 3 => 7, 4 => 5, 5 => 3] as $level => $percentage) {
            DB::table('referral_commission_rates')->insert([
                'level' => $level, 'percentage' => $percentage, 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
        Schema::dropIfExists('referral_commission_rates');
    }
};
