<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'pending_balance',
    'approved_balance',
    'locked_balance',
    'withdrawn_balance',
    'reversed_balance',
    'total_earned',
    'currency',
    'min_withdrawal_amount',
    'is_frozen',
    'freeze_reason',
    'last_transaction_at',
    'is_deleted',
])]
class UserWallet extends Model
{
    protected $table = 'user_wallets';

    protected function casts(): array
    {
        return [
            'pending_balance' => 'decimal:2',
            'approved_balance' => 'decimal:2',
            'locked_balance' => 'decimal:2',
            'withdrawn_balance' => 'decimal:2',
            'reversed_balance' => 'decimal:2',
            'total_earned' => 'decimal:2',
            'min_withdrawal_amount' => 'decimal:2',
            'is_frozen' => 'boolean',
            'last_transaction_at' => 'datetime',
        ];
    }

    /** Jo paisa abhi nikala ja sakta hai. */
    public function withdrawableBalance(): float
    {
        return round((float) $this->approved_balance - (float) $this->locked_balance, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id', 'id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'user_id', 'user_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'user_id', 'user_id');
    }
}