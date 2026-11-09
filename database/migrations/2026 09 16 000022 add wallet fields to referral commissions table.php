<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_commissions', function (Blueprint $table) {
            // Commission kis base amount par bana - audit ke liye zaroori.
            if (! Schema::hasColumn('referral_commissions', 'base_amount')) {
                $table->decimal('base_amount', 12, 2)->default(0)->after('commission_percent');
            }
            if (! Schema::hasColumn('referral_commissions', 'currency')) {
                $table->string('currency', 3)->default('INR')->after('commission_amount');
            }

            // Source ka reference (subscription / quiz / ecom ityadi).
            if (! Schema::hasColumn('referral_commissions', 'source_type')) {
                $table->string('source_type', 40)->default('role_subscription')->after('currency');
            }
            if (! Schema::hasColumn('referral_commissions', 'source_id')) {
                $table->string('source_id')->nullable()->after('source_type');
            }
            if (! Schema::hasColumn('referral_commissions', 'plan_id')) {
                $table->string('plan_id')->nullable()->after('source_id');
            }

            // Lifecycle timestamps - pending -> approved -> paid / reversed
            $table->timestamp('available_at')->nullable();      // hold period khatam hone ki date
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Wallet ledger se link.
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->unsignedBigInteger('withdrawal_request_id')->nullable();
            $table->text('remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('referral_commissions', function (Blueprint $table) {
            $table->dropColumn([
                'base_amount', 'currency', 'source_type', 'source_id', 'plan_id',
                'available_at', 'approved_at', 'approved_by', 'reversed_at', 'reversed_by',
                'reversal_reason', 'paid_at', 'wallet_transaction_id', 'withdrawal_request_id', 'remarks',
            ]);
        });
    }
};