<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionRate;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UPDATED - ab commission banne ke saath wallet me pending entry bhi jaati hai.
 * (Purane file ko isse replace kar dein.)
 */
class ReferralCommissionService
{
    public function __construct(protected WalletService $wallet)
    {
    }

    public function isEligible(User $user): bool
    {
        return UserSubscription::query()->active()->where('user_id', (string) $user->id)->exists();
    }

    /** Admin ne rate table mein row na banayi ho tab ye default % lagte hain. */
    public const DEFAULT_RATES = [1 => 10, 2 => 5, 3 => 3, 4 => 2, 5 => 1];

    /**
     * Role subscription payment 'paid' hone ke baad upar ke 5 levels ko commission do:
     * referral_commissions mein row + referrer ke wallet (pending bucket) mein credit.
     * Idempotent: same payment par dobara chalane se double credit nahi hota.
     */
    public function createForPaidRoleSubscription(SubscriptionPayment $payment): void
    {
        if ($payment->subscription_type !== 'role' || $payment->status !== 'paid') {
            return;
        }

        $joiningUser = User::query()->find($payment->user_id);
        $relationship = Referral::query()
            ->where('user_id', (string) $payment->user_id)
            ->where('is_deleted', 1)
            ->first();

        if (! $joiningUser || ! $relationship) {
            return;
        }

        // Admin ka set kiya hua rate (status=1) use hota hai; row hi na ho to default.
        $rateRows = ReferralCommissionRate::query()->get()->keyBy('level');
        $columns = $this->commissionColumns();

        $parentId = (string) $relationship->from_user_id;
        $visited = [(string) $payment->user_id => true];

        for ($level = 1; $level <= 5 && $parentId; $level++) {
            if (isset($visited[$parentId])) {
                break; // referral loop se bachav
            }
            $visited[$parentId] = true;

            $recipient = User::query()->find($parentId);
            if (! $recipient) {
                break;
            }

            $row = $rateRows->get($level);
            $percentage = $row ? ($row->status ? (float) $row->percentage : null) : (self::DEFAULT_RATES[$level] ?? null);

            if ($percentage !== null && $percentage > 0 && $this->isEligible($recipient)) {
                DB::transaction(function () use ($payment, $recipient, $relationship, $parentId, $joiningUser, $level, $percentage, $columns) {
                    $values = [
                        'referral_id' => $relationship->id,
                        'from_user_id' => (string) $parentId,
                        'referred_user_id' => (string) $joiningUser->id,
                        'level' => $level,
                        'commission_percent' => $percentage,
                        'commission_amount' => round((float) $payment->amount * $percentage / 100, 2),
                        'base_amount' => round((float) $payment->amount, 2),
                        'currency' => $payment->currency ?? 'INR',
                        'source_type' => 'role_subscription',
                        'source_id' => (string) $payment->id,
                        'plan_id' => (string) $payment->plan_id,
                        'available_at' => now()->addDays(WalletService::HOLD_DAYS),
                        'status' => 'pending',
                        'is_deleted' => 1,
                    ];

                    // Jo column DB mein nahi hai use skip karo (migration pending ho to bhi commission bane).
                    $values = array_intersect_key($values, array_flip($columns));

                    $commission = ReferralCommission::query()->firstOrCreate(
                        ['subscription_payment_id' => $payment->id, 'user_id' => (string) $recipient->id],
                        $values
                    );

                    // Sirf pehli baar wallet mein credit (double credit se bachav).
                    if ($commission->wasRecentlyCreated) {
                        $this->wallet->creditPendingCommission($commission);
                    }
                });
            }

            $parentId = (string) Referral::query()
                ->where('user_id', $parentId)
                ->where('is_deleted', 1)
                ->value('from_user_id');
        }
    }

    /** referral_commissions ke actual columns (ek baar nikaal ke cache). */
    protected function commissionColumns(): array
    {
        static $columns = null;

        return $columns ??= Schema::getColumnListing((new ReferralCommission)->getTable());
    }
}
