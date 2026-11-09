<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Har user ka ek wallet snapshot (fast read ke liye).
        Schema::create('user_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique();

            // Balance buckets - admin panel me alag alag dikhane ke liye.
            $table->decimal('pending_balance', 14, 2)->default(0);      // approve hone ka intezaar
            $table->decimal('approved_balance', 14, 2)->default(0);     // approved but abhi withdraw nahi hua
            $table->decimal('locked_balance', 14, 2)->default(0);       // withdrawal request me block
            $table->decimal('withdrawn_balance', 14, 2)->default(0);    // paid out ho chuka
            $table->decimal('reversed_balance', 14, 2)->default(0);     // refund/fraud par wapas liya gaya
            $table->decimal('total_earned', 14, 2)->default(0);         // lifetime approved earning

            $table->string('currency', 3)->default('INR');
            $table->decimal('min_withdrawal_amount', 10, 2)->default(500);
            $table->boolean('is_frozen')->default(false);               // admin wallet freeze kar sakta hai
            $table->string('freeze_reason')->nullable();
            $table->timestamp('last_transaction_at')->nullable();
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamps();
        });

        // withdrawable_balance = approved_balance - locked_balance

        // 2) Har paisa movement ka immutable ledger.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->string('user_id');

            // commission_pending | commission_approved | commission_reversed |
            // withdrawal_requested | withdrawal_paid | withdrawal_refunded |
            // adjustment_credit | adjustment_debit
            $table->string('type', 40);
            $table->string('direction', 10);            // credit | debit
            $table->decimal('amount', 14, 2);

            // Transaction ke baad ka snapshot - audit ke liye.
            $table->decimal('pending_balance_after', 14, 2)->default(0);
            $table->decimal('approved_balance_after', 14, 2)->default(0);
            $table->decimal('locked_balance_after', 14, 2)->default(0);

            // Source kis record se aaya.
            $table->string('reference_type', 40)->nullable();  // referral_commission | withdrawal_request | manual
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('description')->nullable();
            $table->json('meta')->nullable();                  // level, referred_user, payment_id etc.
            $table->string('performed_by')->nullable();         // admin id, ya 'system'
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['created_at']);
        });

        // 3) Withdrawal / payout requests.
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_code', 30)->unique();      // WDR-2026-000123
            $table->string('user_id');

            $table->decimal('amount', 14, 2);                  // user ne jitna maanga
            $table->decimal('fee', 10, 2)->default(0);         // platform fee
            $table->decimal('tds_amount', 10, 2)->default(0);  // TDS deduction
            $table->decimal('net_amount', 14, 2);              // haath me jitna jayega

            $table->string('method', 20)->default('bank');     // bank | upi
            $table->string('account_holder_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('upi_id')->nullable();
            $table->string('pan_number', 20)->nullable();

            // pending | approved | processing | paid | rejected | cancelled
            $table->string('status', 20)->default('pending');

            $table->string('payout_reference')->nullable();    // UTR / transaction ref
            $table->string('payout_mode', 30)->nullable();     // NEFT / IMPS / UPI / manual
            $table->text('admin_remarks')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('processed_by')->nullable();        // admin user id

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->json('meta')->nullable();
            $table->tinyInteger('is_deleted')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('user_wallets');
    }
};