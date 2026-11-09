<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'wallet_id',
    'user_id',
    'type',
    'direction',
    'amount',
    'pending_balance_after',
    'approved_balance_after',
    'locked_balance_after',
    'reference_type',
    'reference_id',
    'description',
    'meta',
    'performed_by',
    'is_deleted',
])]
class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    public const TYPE_COMMISSION_PENDING = 'commission_pending';
    public const TYPE_COMMISSION_APPROVED = 'commission_approved';
    public const TYPE_COMMISSION_REVERSED = 'commission_reversed';
    public const TYPE_WITHDRAWAL_REQUESTED = 'withdrawal_requested';
    public const TYPE_WITHDRAWAL_PAID = 'withdrawal_paid';
    public const TYPE_WITHDRAWAL_REFUNDED = 'withdrawal_refunded';
    public const TYPE_ADJUSTMENT_CREDIT = 'adjustment_credit';
    public const TYPE_ADJUSTMENT_DEBIT = 'adjustment_debit';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'pending_balance_after' => 'decimal:2',
            'approved_balance_after' => 'decimal:2',
            'locked_balance_after' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(UserWallet::class, 'wallet_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}