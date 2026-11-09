<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Models\UserWallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin side - Point 18: Wallet & Commission Record.
 *
 * Prefix: /api/admin-rti  (aur /api/rti-admin)
 * Middleware: AuthenticateWithJwt + EnsureAdminUser
 */
class AdminWalletController extends Controller
{
    public function __construct(protected WalletService $wallet)
    {
    }

    /** GET /admin-rti/wallet-overview - dashboard cards. */
    public function overview(): JsonResponse
    {
        $commissionTotals = ReferralCommission::query()
            ->where('is_deleted', 1)
            ->selectRaw('status, COUNT(*) as c, COALESCE(SUM(commission_amount),0) as amt')
            ->groupBy('status')->get()->keyBy('status');

        $take = fn (string $s, string $k) => $k === 'amt'
            ? round((float) ($commissionTotals[$s]->amt ?? 0), 2)
            : (int) ($commissionTotals[$s]->c ?? 0);

        return response()->json([
            'commission' => [
                'pending' => ['count' => $take('pending', 'c'), 'amount' => $take('pending', 'amt')],
                'approved' => ['count' => $take('approved', 'c'), 'amount' => $take('approved', 'amt')],
                'reversed' => ['count' => $take('reversed', 'c'), 'amount' => $take('reversed', 'amt')],
                'paid' => ['count' => $take('paid', 'c'), 'amount' => $take('paid', 'amt')],
            ],
            'wallet' => [
                'total_pending_balance' => round((float) UserWallet::query()->sum('pending_balance'), 2),
                'total_approved_balance' => round((float) UserWallet::query()->sum('approved_balance'), 2),
                'total_locked_balance' => round((float) UserWallet::query()->sum('locked_balance'), 2),
                'total_withdrawn' => round((float) UserWallet::query()->sum('withdrawn_balance'), 2),
                'total_reversed' => round((float) UserWallet::query()->sum('reversed_balance'), 2),
                'total_liability' => round(
                    (float) UserWallet::query()->sum('pending_balance') + (float) UserWallet::query()->sum('approved_balance'),
                    2
                ),
                'wallets_count' => UserWallet::query()->count(),
                'frozen_wallets' => UserWallet::query()->where('is_frozen', true)->count(),
            ],
            'withdrawals' => [
                'pending' => WithdrawalRequest::query()->where('status', 'pending')->count(),
                'pending_amount' => round((float) WithdrawalRequest::query()->where('status', 'pending')->sum('amount'), 2),
                'approved' => WithdrawalRequest::query()->where('status', 'approved')->count(),
                'paid' => WithdrawalRequest::query()->where('status', 'paid')->count(),
                'rejected' => WithdrawalRequest::query()->where('status', 'rejected')->count(),
            ],
        ]);
    }

    /** GET /admin-rti/wallets?user_id=... - selected user's balance and ledger. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $userId = (string) $validated['user_id'];
        $user = User::query()->findOrFail($userId);
        $transactions = WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('is_deleted', 1)
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'user' => $this->formatUser($user),
            'wallet' => $this->wallet->summary($userId),
            'transactions' => collect($transactions->items())
                ->map(fn (WalletTransaction $transaction) => $this->formatTransaction($transaction)),
            'meta' => $this->paginationMeta($transactions),
        ]);
    }

    /** GET /admin-rti/wallets/{userId} - ek user ki poori wallet detail. */
    public function show(string $userId): JsonResponse
    {
        $user = User::query()->find($userId);

        return response()->json([
            'user' => $user ? $this->formatUser($user) : null,
            'summary' => $this->wallet->summary($userId),
            'recent_commissions' => ReferralCommission::query()
                ->with(['referredUser', 'fromUser'])
                ->where('user_id', (string) $userId)->where('is_deleted', 1)
                ->latest('id')->limit(10)->get()
                ->map(fn (ReferralCommission $c) => $this->formatCommission($c)),
            'recent_transactions' => WalletTransaction::query()
                ->where('user_id', (string) $userId)->where('is_deleted', 1)
                ->latest('id')->limit(10)->get()
                ->map(fn (WalletTransaction $t) => $this->formatTransaction($t)),
            'recent_withdrawals' => WithdrawalRequest::query()
                ->where('user_id', (string) $userId)->where('is_deleted', 1)
                ->latest('id')->limit(10)->get()
                ->map(fn (WithdrawalRequest $w) => $this->formatWithdrawal($w, true)),
        ]);
    }

    /** GET /admin-rti/commissions - commission record list with filters. */
    public function commissions(Request $request): JsonResponse
    {
        $query = ReferralCommission::query()
            ->with(['user', 'fromUser', 'referredUser'])
            ->where('is_deleted', 1);

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->user_id);
        }
        if ($request->filled('referred_user_id')) {
            $query->where('referred_user_id', (string) $request->referred_user_id);
        }
        if ($request->filled('level')) {
            $query->where('level', (int) $request->level);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        if ($request->filled('min_amount')) {
            $query->where('commission_amount', '>=', (float) $request->min_amount);
        }

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(commission_amount),0) as total_amount')
            ->first();

        $commissions = $query->latest('id')->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'totals' => [
                'count' => (int) $totals->total_count,
                'amount' => round((float) $totals->total_amount, 2),
            ],
            'data' => collect($commissions->items())->map(fn (ReferralCommission $c) => $this->formatCommission($c)),
            'meta' => $this->paginationMeta($commissions),
        ]);
    }

    /** GET /admin-rti/commissions/{id} */
    public function commissionShow(int $id): JsonResponse
    {
        $commission = ReferralCommission::query()
            ->with(['user', 'fromUser', 'referredUser', 'referral'])
            ->findOrFail($id);

        return response()->json(['commission' => $this->formatCommission($commission, true)]);
    }

    /** PATCH /admin-rti/commissions/{id}/approve */
    public function approveCommission(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);
        $commission = ReferralCommission::query()->findOrFail($id);

        $commission = $this->wallet->approveCommission(
            $commission,
            (string) $request->user()->id,
            $data['remarks'] ?? null
        );

        return response()->json([
            'message' => 'Commission approved and added to withdrawable balance.',
            'commission' => $this->formatCommission($commission->load(['user', 'referredUser'])),
            'wallet' => $this->wallet->summary((string) $commission->user_id),
        ]);
    }

    /** PATCH /admin-rti/commissions/{id}/reverse */
    public function reverseCommission(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $commission = ReferralCommission::query()->findOrFail($id);

        $commission = $this->wallet->reverseCommission($commission, $data['reason'], (string) $request->user()->id);

        return response()->json([
            'message' => 'Commission reversed.',
            'commission' => $this->formatCommission($commission->load(['user', 'referredUser'])),
            'wallet' => $this->wallet->summary((string) $commission->user_id),
        ]);
    }

    /** POST /admin-rti/commissions/bulk-approve  { ids: [1,2,3] } */
    public function bulkApproveCommissions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $approved = [];
        $failed = [];

        foreach ($data['ids'] as $id) {
            $commission = ReferralCommission::query()->find($id);

            if (! $commission) {
                $failed[] = ['id' => $id, 'reason' => 'Not found.'];
                continue;
            }

            try {
                $this->wallet->approveCommission($commission, (string) $request->user()->id, $data['remarks'] ?? null);
                $approved[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => count($approved).' commission(s) approved.',
            'approved_ids' => $approved,
            'failed' => $failed,
        ]);
    }

    /** GET /admin-rti/wallet-transactions - poora ledger. */
    public function transactions(Request $request): JsonResponse
    {
        $query = WalletTransaction::query()->with('user')->where('is_deleted', 1);

        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->user_id);
        }
        if ($request->filled('type')) {
            $query->whereIn('type', (array) $request->type);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $transactions = $query->latest('id')->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'totals' => [
                'credit' => round((float) (clone $query)->where('direction', 'credit')->sum('amount'), 2),
                'debit' => round((float) (clone $query)->where('direction', 'debit')->sum('amount'), 2),
            ],
            'data' => collect($transactions->items())->map(fn (WalletTransaction $t) => $this->formatTransaction($t, true)),
            'meta' => $this->paginationMeta($transactions),
        ]);
    }

    /** GET /admin-rti/withdrawals */
    public function withdrawals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $query = WithdrawalRequest::query()
            ->with('user')
            ->where('is_deleted', 1)
            ->where('user_id', (string) $validated['user_id']);

        if ($request->filled('status')) {
            $query->where('status', $validated['status']);
        }

        $withdrawals = $query->latest()->paginate($request->integer('per_page', 20));

        return response()->json([
            'totals' => [
                'count' => $withdrawals->total(),
                'amount' => round((float) (clone $query)->sum('amount'), 2),
                'net_amount' => round((float) (clone $query)->sum('net_amount'), 2),
            ],
            'data' => collect($withdrawals->items())->map(fn (WithdrawalRequest $w) => $this->formatWithdrawal($w)),
            'meta' => $this->paginationMeta($withdrawals),
        ]);
    }

    /**
     * DELETE /admin-rti/wallet/{id} (alias: /wallet-transactions/{id}) - wallet ledger record soft delete.
     * Sirf list se hide hota hai (is_deleted = 0); wallet balance par koi asar nahi padta.
     */
    public function deleteTransaction(int $id): JsonResponse
    {
        $transaction = WalletTransaction::query()->where('is_deleted', 1)->findOrFail($id);

        $transaction->forceFill(['is_deleted' => 0])->save();

        return response()->json(['message' => 'Wallet transaction deleted successfully.']);
    }

    /**
     * DELETE /admin-rti/withdrawals/{id} - withdrawal record soft delete.
     * Pending / approved / processing request me paisa locked hota hai, isliye pehle reject ya cancel karna hoga.
     */
    public function deleteWithdrawal(int $id): JsonResponse
    {
        $withdrawal = WithdrawalRequest::query()->where('is_deleted', 1)->findOrFail($id);

        if (in_array($withdrawal->status, [
            WithdrawalRequest::STATUS_PENDING,
            WithdrawalRequest::STATUS_APPROVED,
            WithdrawalRequest::STATUS_PROCESSING,
        ], true)) {
            return response()->json([
                'message' => 'This withdrawal is still active and its amount is locked. Reject or cancel it before deleting.',
                'code' => 'withdrawal_active',
            ], 422);
        }

        $withdrawal->forceFill(['is_deleted' => 0])->save();

        return response()->json(['message' => 'Withdrawal deleted successfully.']);
    }

    /** GET /admin-rti/withdrawals/{id} - full payout details. */
    public function withdrawalShow(int $id): JsonResponse
    {
        $withdrawal = WithdrawalRequest::query()->with('user')->findOrFail($id);

        return response()->json([
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
            'wallet' => $this->wallet->summary((string) $withdrawal->user_id),
        ]);
    }

    /** PATCH /admin-rti/withdrawals/{id}/approve */
    public function approveWithdrawal(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['admin_remarks' => ['nullable', 'string', 'max:500']]);
        $withdrawal = WithdrawalRequest::query()->findOrFail($id);

        $withdrawal = $this->wallet->approveWithdrawal($withdrawal, (string) $request->user()->id, $data['admin_remarks'] ?? null);

        return response()->json([
            'message' => 'Withdrawal approved. Mark it as paid once the payout is done.',
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
        ]);
    }

    /** PATCH /admin-rti/withdrawals/{id}/mark-paid */
    public function markWithdrawalPaid(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payout_reference' => ['required', 'string', 'max:191'],
            'payout_mode' => ['nullable', Rule::in(['NEFT', 'IMPS', 'RTGS', 'UPI', 'manual'])],
            'admin_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $withdrawal = WithdrawalRequest::query()->findOrFail($id);
        $withdrawal = $this->wallet->markWithdrawalPaid($withdrawal, $data, (string) $request->user()->id);

        return response()->json([
            'message' => 'Withdrawal marked as paid.',
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
            'wallet' => $this->wallet->summary((string) $withdrawal->user_id),
        ]);
    }

    /** PATCH /admin-rti/withdrawals/{id}/reject */
    public function rejectWithdrawal(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $withdrawal = WithdrawalRequest::query()->findOrFail($id);

        $withdrawal = $this->wallet->rejectWithdrawal($withdrawal, $data['reason'], (string) $request->user()->id);

        return response()->json([
            'message' => 'Withdrawal rejected and the locked amount has been released.',
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
            'wallet' => $this->wallet->summary((string) $withdrawal->user_id),
        ]);
    }

    /** POST /admin-rti/wallets/{userId}/adjust - manual credit/debit. */
    public function adjust(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $transaction = $this->wallet->adjust(
            $userId, (float) $data['amount'], $data['direction'], $data['description'], (string) $request->user()->id
        );

        return response()->json([
            'message' => 'Wallet adjusted.',
            'transaction' => $this->formatTransaction($transaction),
            'wallet' => $this->wallet->summary($userId),
        ]);
    }

    /** PATCH /admin-rti/wallets/{userId}/freeze */
    public function freeze(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'is_frozen' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->wallet->setFrozen($userId, (bool) $data['is_frozen'], $data['reason'] ?? null);

        return response()->json([
            'message' => $data['is_frozen'] ? 'Wallet frozen.' : 'Wallet unfrozen.',
            'wallet' => $this->wallet->summary($userId),
        ]);
    }

    /** POST /admin-rti/wallets/{userId}/recalculate - ledger se balance dobara banao. */
    public function recalculate(string $userId): JsonResponse
    {
        $this->wallet->recalculate($userId);

        return response()->json([
            'message' => 'Wallet balances recalculated from the commission and withdrawal ledger.',
            'wallet' => $this->wallet->summary($userId),
        ]);
    }

    // ---------------- formatters ----------------

    protected function formatWallet(UserWallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'user' => $wallet->user ? $this->formatUser($wallet->user) : ['id' => $wallet->user_id],
            'pending_commission' => round((float) $wallet->pending_balance, 2),
            'approved_commission' => round((float) $wallet->approved_balance, 2),
            'reversed_commission' => round((float) $wallet->reversed_balance, 2),
            'withdrawable_balance' => $wallet->withdrawableBalance(),
            'locked_balance' => round((float) $wallet->locked_balance, 2),
            'withdrawn_balance' => round((float) $wallet->withdrawn_balance, 2),
            'total_earned' => round((float) $wallet->total_earned, 2),
            'currency' => $wallet->currency,
            'is_frozen' => (bool) $wallet->is_frozen,
            'freeze_reason' => $wallet->freeze_reason,
            'last_transaction_at' => $wallet->last_transaction_at,
        ];
    }

    protected function formatCommission(ReferralCommission $c, bool $detailed = false): array
    {
        $data = [
            'id' => $c->id,
            'status' => $c->status,
            'level' => (int) $c->level,
            'commission_percent' => (float) $c->commission_percent,
            'commission_amount' => round((float) $c->commission_amount, 2),
            'base_amount' => round((float) ($c->base_amount ?? 0), 2),
            'currency' => $c->currency ?? 'INR',
            'source_type' => $c->source_type ?? 'role_subscription',
            'source_id' => $c->source_id,
            'subscription_payment_id' => $c->subscription_payment_id,

            // Commission kisko mila, kisse aaya, kiski joining se bana
            'earned_by' => $c->relationLoaded('user') && $c->user ? $this->formatUser($c->user) : ['id' => $c->user_id],
            'from_user' => $c->relationLoaded('fromUser') && $c->fromUser ? $this->formatUser($c->fromUser) : ['id' => $c->from_user_id],
            'referred_user' => $c->relationLoaded('referredUser') && $c->referredUser ? $this->formatUser($c->referredUser) : ['id' => $c->referred_user_id],

            'created_at' => $c->created_at,
            'approved_at' => $c->approved_at,
            'reversed_at' => $c->reversed_at,
            'paid_at' => $c->paid_at,
        ];

        if ($detailed) {
            $data += [
                'referral_id' => $c->referral_id,
                'plan_id' => $c->plan_id,
                'available_at' => $c->available_at,
                'approved_by' => $c->approved_by,
                'reversed_by' => $c->reversed_by,
                'reversal_reason' => $c->reversal_reason,
                'remarks' => $c->remarks,
                'wallet_transaction_id' => $c->wallet_transaction_id,
                'withdrawal_request_id' => $c->withdrawal_request_id,
                'updated_at' => $c->updated_at,
            ];
        }

        return $data;
    }

    protected function formatTransaction(WalletTransaction $t, bool $withUser = false): array
    {
        $data = [
            'id' => $t->id,
            'type' => $t->type,
            'direction' => $t->direction,
            'amount' => round((float) $t->amount, 2),
            'description' => $t->description,
            'reference_type' => $t->reference_type,
            'reference_id' => $t->reference_id,
            'balance_after' => [
                'pending' => round((float) $t->pending_balance_after, 2),
                'approved' => round((float) $t->approved_balance_after, 2),
                'locked' => round((float) $t->locked_balance_after, 2),
                'withdrawable' => round((float) $t->approved_balance_after - (float) $t->locked_balance_after, 2),
            ],
            'meta' => $t->meta,
            'performed_by' => $t->performed_by,
            'created_at' => $t->created_at,
        ];

        if ($withUser) {
            $data['user'] = $t->relationLoaded('user') && $t->user ? $this->formatUser($t->user) : ['id' => $t->user_id];
        }

        return $data;
    }

    protected function formatWithdrawal(WithdrawalRequest $w, bool $detailed = false): array
    {
        $data = [
            'id' => $w->id,
            'request_code' => $w->request_code,
            'user' => $w->relationLoaded('user') && $w->user ? $this->formatUser($w->user) : ['id' => $w->user_id],
            'amount' => round((float) $w->amount, 2),
            'fee' => round((float) $w->fee, 2),
            'tds_amount' => round((float) $w->tds_amount, 2),
            'net_amount' => round((float) $w->net_amount, 2),
            'method' => $w->method,
            'status' => $w->status,
            'account_number_masked' => $w->maskedAccountNumber(),
            'upi_id' => $w->upi_id,
            'requested_at' => $w->requested_at,
            'paid_at' => $w->paid_at,
        ];

        if ($detailed) {
            $data += [
                'account_holder_name' => $w->account_holder_name,
                'account_number' => $w->account_number,
                'ifsc_code' => $w->ifsc_code,
                'bank_name' => $w->bank_name,
                'payout_reference' => $w->payout_reference,
                'payout_mode' => $w->payout_mode,
                'admin_remarks' => $w->admin_remarks,
                'rejection_reason' => $w->rejection_reason,
                'processed_by' => $w->processed_by,
                'approved_at' => $w->approved_at,
                'rejected_at' => $w->rejected_at,
                'created_at' => $w->created_at,
                'updated_at' => $w->updated_at,
            ];
        }

        return $data;
    }

    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'phone' => $user->phone,
            'referral_code' => $user->referralCode(),
        ];
    }

    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
