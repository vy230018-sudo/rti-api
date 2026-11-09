<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCommission;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** User side wallet - app me dikhne wale screens ke liye. */
class WalletController extends Controller
{
    public function __construct(protected WalletService $wallet)
    {
    }

    /** GET /wallet */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['wallet' => $this->wallet->summary((string) $request->user()->id)]);
    }

    /** GET /wallet/commissions?status=pending */
    public function commissions(Request $request): JsonResponse
    {
        $query = ReferralCommission::query()
            ->with('referredUser')
            ->where('user_id', (string) $request->user()->id)
            ->where('is_deleted', 1);

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        }
        if ($request->filled('level')) {
            $query->where('level', (int) $request->level);
        }

        $commissions = $query->latest('id')->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'data' => collect($commissions->items())->map(fn (ReferralCommission $c) => [
                'id' => $c->id,
                'level' => (int) $c->level,
                'commission_percent' => (float) $c->commission_percent,
                'amount' => round((float) $c->commission_amount, 2),
                'status' => $c->status,
                'source_type' => $c->source_type ?? 'role_subscription',
                'referred_user' => $c->referredUser ? [
                    'id' => $c->referredUser->id,
                    'name' => trim($c->referredUser->firstname.' '.$c->referredUser->lastname),
                ] : ['id' => $c->referred_user_id],
                'reversal_reason' => $c->reversal_reason,
                'created_at' => $c->created_at,
                'approved_at' => $c->approved_at,
            ]),
            'meta' => [
                'current_page' => $commissions->currentPage(),
                'total' => $commissions->total(),
                'last_page' => $commissions->lastPage(),
            ],
        ]);
    }

    /** GET /wallet/transactions */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', (string) $request->user()->id)
            ->where('is_deleted', 1)
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', (array) $request->type))
            ->latest('id')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'data' => collect($transactions->items())->map(fn (WalletTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'direction' => $t->direction,
                'amount' => round((float) $t->amount, 2),
                'description' => $t->description,
                'withdrawable_after' => round((float) $t->approved_balance_after - (float) $t->locked_balance_after, 2),
                'created_at' => $t->created_at,
            ]),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    /** GET /wallet/withdrawals */
    public function withdrawals(Request $request): JsonResponse
    {
        $withdrawals = WithdrawalRequest::query()
            ->where('user_id', (string) $request->user()->id)
            ->where('is_deleted', 1)
            ->latest('id')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'data' => collect($withdrawals->items())->map(fn (WithdrawalRequest $w) => [
                'id' => $w->id,
                'request_code' => $w->request_code,
                'amount' => round((float) $w->amount, 2),
                'net_amount' => round((float) $w->net_amount, 2),
                'method' => $w->method,
                'status' => $w->status,
                'account_number_masked' => $w->maskedAccountNumber(),
                'upi_id' => $w->upi_id,
                'payout_reference' => $w->payout_reference,
                'rejection_reason' => $w->rejection_reason,
                'requested_at' => $w->requested_at,
                'paid_at' => $w->paid_at,
            ]),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'total' => $withdrawals->total(),
                'last_page' => $withdrawals->lastPage(),
            ],
        ]);
    }

    /** POST /wallet/withdrawals */
    public function storeWithdrawal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in(['bank', 'upi'])],
            'account_holder_name' => ['required_if:method,bank', 'nullable', 'string', 'max:191'],
            'account_number' => ['required_if:method,bank', 'nullable', 'string', 'max:30'],
            'ifsc_code' => ['required_if:method,bank', 'nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:191'],
            'upi_id' => ['required_if:method,upi', 'nullable', 'string', 'max:191'],
            'pan_number' => ['nullable', 'string', 'max:20'],
        ]);

        $withdrawal = $this->wallet->createWithdrawal((string) $request->user()->id, $data);

        return response()->json([
            'message' => 'Withdrawal request submitted. Admin approval ke baad payout process hoga.',
            'withdrawal' => [
                'id' => $withdrawal->id,
                'request_code' => $withdrawal->request_code,
                'amount' => round((float) $withdrawal->amount, 2),
                'net_amount' => round((float) $withdrawal->net_amount, 2),
                'status' => $withdrawal->status,
                'requested_at' => $withdrawal->requested_at,
            ],
            'wallet' => $this->wallet->summary((string) $request->user()->id),
        ], 201);
    }

    /** PATCH /wallet/withdrawals/{id}/cancel - pending request user khud cancel kar sakta hai. */
    public function cancelWithdrawal(Request $request, int $id): JsonResponse
    {
        $withdrawal = WithdrawalRequest::query()
            ->where('user_id', (string) $request->user()->id)
            ->where('status', WithdrawalRequest::STATUS_PENDING)
            ->findOrFail($id);

        $this->wallet->rejectWithdrawal(
            $withdrawal,
            'Cancelled by user.',
            (string) $request->user()->id,
            WithdrawalRequest::STATUS_CANCELLED
        );

        return response()->json([
            'message' => 'Withdrawal request cancelled.',
            'wallet' => $this->wallet->summary((string) $request->user()->id),
        ]);
    }
}