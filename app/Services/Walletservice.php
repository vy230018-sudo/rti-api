<?php

namespace App\Services;

use App\Models\ReferralCommission;
use App\Models\UserWallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wallet ka single source of truth.
 *
 * Rule: koi bhi balance seedha update nahi hota. Har change yahin se hota hai
 * aur uske saath ek wallet_transactions row banti hai, taki admin panel me
 * poora audit trail mile.
 *
 * Balance flow:
 *   commission create  -> pending_balance +
 *   admin approve      -> pending_balance -, approved_balance +, total_earned +
 *   admin reverse      -> pending/approved -, reversed_balance +
 *   withdrawal request -> locked_balance +
 *   withdrawal paid    -> locked_balance -, approved_balance -, withdrawn_balance +
 *   withdrawal reject  -> locked_balance -   (paisa wapas withdrawable me)
 */
class WalletService
{
    public const HOLD_DAYS = 7; // commission kitne din baad approve-eligible hoga

    /** Wallet nikaalo, na ho to bana do. */
    public function wallet(string $userId): UserWallet
    {
        return UserWallet::query()->firstOrCreate(
            ['user_id' => (string) $userId],
            ['currency' => 'INR', 'min_withdrawal_amount' => 500, 'is_deleted' => 1]
        );
    }

    /** Admin panel / user app dono ke liye ek hi summary shape. */
    public function summary(string $userId): array
    {
        $wallet = $this->wallet($userId);

        $byStatus = ReferralCommission::query()
            ->where('user_id', (string) $userId)
            ->where('is_deleted', 1)
            ->selectRaw('status, COUNT(*) as total_count, COALESCE(SUM(commission_amount), 0) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $amount = fn (string $key) => round((float) ($byStatus[$key]->total_amount ?? 0), 2);
        $count = fn (string $key) => (int) ($byStatus[$key]->total_count ?? 0);

        return [
            'user_id' => (string) $userId,
            'currency' => $wallet->currency,

            // 4 buckets jo alag alag dikhne chahiye
            'pending_commission' => round((float) $wallet->pending_balance, 2),
            'approved_commission' => round((float) $wallet->approved_balance, 2),
            'reversed_commission' => round((float) $wallet->reversed_balance, 2),
            'withdrawable_balance' => $wallet->withdrawableBalance(),

            'locked_balance' => round((float) $wallet->locked_balance, 2),
            'withdrawn_balance' => round((float) $wallet->withdrawn_balance, 2),
            'total_earned' => round((float) $wallet->total_earned, 2),

            'min_withdrawal_amount' => round((float) $wallet->min_withdrawal_amount, 2),
            'is_frozen' => (bool) $wallet->is_frozen,
            'freeze_reason' => $wallet->freeze_reason,
            'can_withdraw' => ! $wallet->is_frozen
                && $wallet->withdrawableBalance() >= (float) $wallet->min_withdrawal_amount,

            'counts' => [
                'pending' => $count('pending'),
                'approved' => $count('approved'),
                'reversed' => $count('reversed'),
                'paid' => $count('paid'),
            ],
            'commission_totals' => [
                'pending' => $amount('pending'),
                'approved' => $amount('approved'),
                'reversed' => $amount('reversed'),
                'paid' => $amount('paid'),
            ],
            'pending_withdrawal_count' => WithdrawalRequest::query()
                ->where('user_id', (string) $userId)->blocking()->where('is_deleted', 1)->count(),
            'last_transaction_at' => $wallet->last_transaction_at,
        ];
    }

    /** Commission ban-ne par pending bucket me credit. */
    public function creditPendingCommission(ReferralCommission $commission): WalletTransaction
    {
        return DB::transaction(function () use ($commission) {
            $wallet = $this->lock((string) $commission->user_id);
            $wallet->pending_balance = (float) $wallet->pending_balance + (float) $commission->commission_amount;

            return $this->record($wallet, [
                'type' => WalletTransaction::TYPE_COMMISSION_PENDING,
                'direction' => 'credit',
                'amount' => $commission->commission_amount,
                'reference_type' => 'referral_commission',
                'reference_id' => $commission->id,
                'description' => "Level {$commission->level} referral commission (pending approval)",
                'meta' => [
                    'level' => $commission->level,
                    'commission_percent' => $commission->commission_percent,
                    'referred_user_id' => $commission->referred_user_id,
                    'subscription_payment_id' => $commission->subscription_payment_id,
                ],
                'performed_by' => 'system',
            ]);
        });
    }

    /** Admin approve: pending se approved bucket me shift. */
    public function approveCommission(ReferralCommission $commission, ?string $adminId = null, ?string $remarks = null): ReferralCommission
    {
        if ($commission->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Only pending commissions can be approved. Current status: {$commission->status}."],
            ]);
        }

        return DB::transaction(function () use ($commission, $adminId, $remarks) {
            $wallet = $this->lock((string) $commission->user_id);
            $amount = (float) $commission->commission_amount;

            $wallet->pending_balance = max(0, (float) $wallet->pending_balance - $amount);
            $wallet->approved_balance = (float) $wallet->approved_balance + $amount;
            $wallet->total_earned = (float) $wallet->total_earned + $amount;

            $transaction = $this->record($wallet, [
                'type' => WalletTransaction::TYPE_COMMISSION_APPROVED,
                'direction' => 'credit',
                'amount' => $amount,
                'reference_type' => 'referral_commission',
                'reference_id' => $commission->id,
                'description' => "Level {$commission->level} commission approved",
                'meta' => ['level' => $commission->level, 'remarks' => $remarks],
                'performed_by' => $adminId ?? 'system',
            ]);

            $commission->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $adminId,
                'remarks' => $remarks,
                'wallet_transaction_id' => $transaction->id,
            ])->save();

            return $commission->refresh();
        });
    }

    /** Admin reverse: refund / chargeback / fraud par paisa wapas. */
    public function reverseCommission(ReferralCommission $commission, string $reason, ?string $adminId = null): ReferralCommission
    {
        if (in_array($commission->status, ['reversed', 'paid'], true)) {
            throw ValidationException::withMessages([
                'status' => ["Commission already {$commission->status} and cannot be reversed."],
            ]);
        }

        return DB::transaction(function () use ($commission, $reason, $adminId) {
            $wallet = $this->lock((string) $commission->user_id);
            $amount = (float) $commission->commission_amount;

            if ($commission->status === 'pending') {
                $wallet->pending_balance = max(0, (float) $wallet->pending_balance - $amount);
            } else { // approved
                $wallet->approved_balance = max(0, (float) $wallet->approved_balance - $amount);
                $wallet->total_earned = max(0, (float) $wallet->total_earned - $amount);
            }

            $wallet->reversed_balance = (float) $wallet->reversed_balance + $amount;

            $transaction = $this->record($wallet, [
                'type' => WalletTransaction::TYPE_COMMISSION_REVERSED,
                'direction' => 'debit',
                'amount' => $amount,
                'reference_type' => 'referral_commission',
                'reference_id' => $commission->id,
                'description' => "Level {$commission->level} commission reversed",
                'meta' => ['reason' => $reason, 'previous_status' => $commission->status],
                'performed_by' => $adminId ?? 'system',
            ]);

            $commission->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $adminId,
                'reversal_reason' => $reason,
                'wallet_transaction_id' => $transaction->id,
            ])->save();

            return $commission->refresh();
        });
    }

    /** Manual credit/debit (bonus, correction). */
    public function adjust(string $userId, float $amount, string $direction, string $description, ?string $adminId = null): WalletTransaction
    {
        return DB::transaction(function () use ($userId, $amount, $direction, $description, $adminId) {
            $wallet = $this->lock($userId);
            $amount = round(abs($amount), 2);

            if ($direction === 'credit') {
                $wallet->approved_balance = (float) $wallet->approved_balance + $amount;
                $wallet->total_earned = (float) $wallet->total_earned + $amount;
            } else {
                if ($wallet->withdrawableBalance() < $amount) {
                    throw ValidationException::withMessages(['amount' => ['Insufficient withdrawable balance for this debit.']]);
                }
                $wallet->approved_balance = (float) $wallet->approved_balance - $amount;
            }

            return $this->record($wallet, [
                'type' => $direction === 'credit'
                    ? WalletTransaction::TYPE_ADJUSTMENT_CREDIT
                    : WalletTransaction::TYPE_ADJUSTMENT_DEBIT,
                'direction' => $direction,
                'amount' => $amount,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $description,
                'meta' => ['manual' => true],
                'performed_by' => $adminId ?? 'system',
            ]);
        });
    }

    /** User withdrawal maangta hai -> paisa lock. */
    public function createWithdrawal(string $userId, array $data): WithdrawalRequest
    {
        return DB::transaction(function () use ($userId, $data) {
            $wallet = $this->lock($userId);
            $amount = round((float) $data['amount'], 2);

            if ($wallet->is_frozen) {
                throw ValidationException::withMessages(['amount' => ['Your wallet is currently frozen. Please contact support.']]);
            }

            if ($amount < (float) $wallet->min_withdrawal_amount) {
                throw ValidationException::withMessages([
                    'amount' => ["Minimum withdrawal amount is {$wallet->min_withdrawal_amount}."],
                ]);
            }

            if ($wallet->withdrawableBalance() < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Requested amount is more than your withdrawable balance.'],
                ]);
            }

            $fee = round((float) ($data['fee'] ?? 0), 2);
            $tds = round((float) ($data['tds_amount'] ?? 0), 2);

            $withdrawal = WithdrawalRequest::query()->create([
                'request_code' => $this->nextRequestCode(),
                'user_id' => (string) $userId,
                'amount' => $amount,
                'fee' => $fee,
                'tds_amount' => $tds,
                'net_amount' => round($amount - $fee - $tds, 2),
                'method' => $data['method'] ?? 'bank',
                'account_holder_name' => $data['account_holder_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'ifsc_code' => $data['ifsc_code'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'upi_id' => $data['upi_id'] ?? null,
                'pan_number' => $data['pan_number'] ?? null,
                'status' => WithdrawalRequest::STATUS_PENDING,
                'requested_at' => now(),
                'is_deleted' => 1,
            ]);

            $wallet->locked_balance = (float) $wallet->locked_balance + $amount;

            $this->record($wallet, [
                'type' => WalletTransaction::TYPE_WITHDRAWAL_REQUESTED,
                'direction' => 'debit',
                'amount' => $amount,
                'reference_type' => 'withdrawal_request',
                'reference_id' => $withdrawal->id,
                'description' => "Withdrawal request {$withdrawal->request_code} (amount locked)",
                'meta' => ['method' => $withdrawal->method, 'net_amount' => $withdrawal->net_amount],
                'performed_by' => (string) $userId,
            ]);

            return $withdrawal->refresh();
        });
    }

    /** Admin approve (abhi paisa nahi gaya, sirf queue me aaya). */
    public function approveWithdrawal(WithdrawalRequest $withdrawal, ?string $adminId = null, ?string $remarks = null): WithdrawalRequest
    {
        $this->assertStatus($withdrawal, [WithdrawalRequest::STATUS_PENDING], 'approved');

        $withdrawal->forceFill([
            'status' => WithdrawalRequest::STATUS_APPROVED,
            'approved_at' => now(),
            'processed_by' => $adminId,
            'admin_remarks' => $remarks,
        ])->save();

        return $withdrawal->refresh();
    }

    /** Payout ho gaya -> locked aur approved dono se katega. */
    public function markWithdrawalPaid(WithdrawalRequest $withdrawal, array $data, ?string $adminId = null): WithdrawalRequest
    {
        $this->assertStatus($withdrawal, [
            WithdrawalRequest::STATUS_PENDING,
            WithdrawalRequest::STATUS_APPROVED,
            WithdrawalRequest::STATUS_PROCESSING,
        ], 'paid');

        return DB::transaction(function () use ($withdrawal, $data, $adminId) {
            $wallet = $this->lock((string) $withdrawal->user_id);
            $amount = (float) $withdrawal->amount;

            $wallet->locked_balance = max(0, (float) $wallet->locked_balance - $amount);
            $wallet->approved_balance = max(0, (float) $wallet->approved_balance - $amount);
            $wallet->withdrawn_balance = (float) $wallet->withdrawn_balance + $amount;

            $this->record($wallet, [
                'type' => WalletTransaction::TYPE_WITHDRAWAL_PAID,
                'direction' => 'debit',
                'amount' => $amount,
                'reference_type' => 'withdrawal_request',
                'reference_id' => $withdrawal->id,
                'description' => "Withdrawal {$withdrawal->request_code} paid out",
                'meta' => [
                    'payout_reference' => $data['payout_reference'] ?? null,
                    'payout_mode' => $data['payout_mode'] ?? null,
                    'net_amount' => $withdrawal->net_amount,
                ],
                'performed_by' => $adminId ?? 'system',
            ]);

            $withdrawal->forceFill([
                'status' => WithdrawalRequest::STATUS_PAID,
                'paid_at' => now(),
                'payout_reference' => $data['payout_reference'] ?? null,
                'payout_mode' => $data['payout_mode'] ?? null,
                'admin_remarks' => $data['admin_remarks'] ?? $withdrawal->admin_remarks,
                'processed_by' => $adminId,
            ])->save();

            // Approved commissions ko paid mark karo (FIFO).
            $this->markCommissionsPaid((string) $withdrawal->user_id, $amount, $withdrawal->id);

            return $withdrawal->refresh();
        });
    }

    /** Reject / cancel -> lock hata do, paisa wapas withdrawable me. */
    public function rejectWithdrawal(WithdrawalRequest $withdrawal, string $reason, ?string $adminId = null, string $status = WithdrawalRequest::STATUS_REJECTED): WithdrawalRequest
    {
        $this->assertStatus($withdrawal, [
            WithdrawalRequest::STATUS_PENDING,
            WithdrawalRequest::STATUS_APPROVED,
            WithdrawalRequest::STATUS_PROCESSING,
        ], $status);

        return DB::transaction(function () use ($withdrawal, $reason, $adminId, $status) {
            $wallet = $this->lock((string) $withdrawal->user_id);
            $amount = (float) $withdrawal->amount;

            $wallet->locked_balance = max(0, (float) $wallet->locked_balance - $amount);

            $this->record($wallet, [
                'type' => WalletTransaction::TYPE_WITHDRAWAL_REFUNDED,
                'direction' => 'credit',
                'amount' => $amount,
                'reference_type' => 'withdrawal_request',
                'reference_id' => $withdrawal->id,
                'description' => "Withdrawal {$withdrawal->request_code} {$status} - amount unlocked",
                'meta' => ['reason' => $reason],
                'performed_by' => $adminId ?? 'system',
            ]);

            $withdrawal->forceFill([
                'status' => $status,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'processed_by' => $adminId,
            ])->save();

            return $withdrawal->refresh();
        });
    }

    /** Wallet freeze / unfreeze. */
    public function setFrozen(string $userId, bool $frozen, ?string $reason = null): UserWallet
    {
        $wallet = $this->wallet($userId);
        $wallet->forceFill(['is_frozen' => $frozen, 'freeze_reason' => $frozen ? $reason : null])->save();

        return $wallet->refresh();
    }

    /**
     * Ledger se balances dobara compute karo.
     * Kabhi mismatch lage to admin panel se yeh chalaya ja sakta hai.
     */
    public function recalculate(string $userId): UserWallet
    {
        return DB::transaction(function () use ($userId) {
            $wallet = $this->lock($userId);

            $sum = fn (string $status) => (float) ReferralCommission::query()
                ->where('user_id', (string) $userId)->where('is_deleted', 1)
                ->where('status', $status)->sum('commission_amount');

            // Withdrawals me is_deleted sirf admin list se hide karta hai. Paisa nikal chuka hai (paid) ya
            // locked hai, isliye balance ke hisab me deleted rows bhi gini jaati hain, warna delete se balance badal jata.
            $withdrawnPaid = (float) WithdrawalRequest::query()
                ->where('user_id', (string) $userId)
                ->where('status', WithdrawalRequest::STATUS_PAID)->sum('amount');

            $locked = (float) WithdrawalRequest::query()
                ->where('user_id', (string) $userId)
                ->blocking()->sum('amount');

            $approved = $sum('approved');
            $paid = $sum('paid');

            $wallet->forceFill([
                'pending_balance' => round($sum('pending'), 2),
                'approved_balance' => round(($approved + $paid) - $withdrawnPaid, 2),
                'reversed_balance' => round($sum('reversed'), 2),
                'locked_balance' => round($locked, 2),
                'withdrawn_balance' => round($withdrawnPaid, 2),
                'total_earned' => round($approved + $paid, 2),
            ])->save();

            return $wallet->refresh();
        });
    }

    // ---------------- internal helpers ----------------

    protected function lock(string $userId): UserWallet
    {
        $this->wallet($userId);

        return UserWallet::query()->where('user_id', (string) $userId)->lockForUpdate()->firstOrFail();
    }

    protected function record(UserWallet $wallet, array $attributes): WalletTransaction
    {
        $wallet->last_transaction_at = now();
        $wallet->save();

        return WalletTransaction::query()->create(array_merge($attributes, [
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'pending_balance_after' => $wallet->pending_balance,
            'approved_balance_after' => $wallet->approved_balance,
            'locked_balance_after' => $wallet->locked_balance,
            'is_deleted' => 1,
        ]));
    }

    protected function markCommissionsPaid(string $userId, float $amount, int $withdrawalId): void
    {
        $remaining = $amount;

        ReferralCommission::query()
            ->where('user_id', (string) $userId)
            ->where('status', 'approved')
            ->where('is_deleted', 1)
            ->orderBy('id')
            ->get()
            ->each(function (ReferralCommission $commission) use (&$remaining, $withdrawalId) {
                if ($remaining <= 0) {
                    return false;
                }

                $commission->forceFill([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'withdrawal_request_id' => $withdrawalId,
                ])->save();

                $remaining -= (float) $commission->commission_amount;

                return true;
            });
    }

    protected function assertStatus(WithdrawalRequest $withdrawal, array $allowed, string $target): void
    {
        if (! in_array($withdrawal->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["A '{$withdrawal->status}' withdrawal cannot be marked as '{$target}'."],
            ]);
        }
    }

    protected function nextRequestCode(): string
    {
        $next = (int) WithdrawalRequest::query()->max('id') + 1;

        return 'WDR-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}