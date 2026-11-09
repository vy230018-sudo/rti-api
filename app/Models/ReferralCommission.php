<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'referral_id',
    'subscription_payment_id',
    'user_id',
    'from_user_id',
    'referred_user_id',
    'level',
    'commission_percent',
    'commission_amount',
    'base_amount',
    'currency',
    'source_type',
    'source_id',
    'plan_id',
    'available_at',
    'status',
    'is_deleted'
])]
class ReferralCommission extends Model
{
    protected $table = 'referral_commissions';

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'referral_id', 'id');
    }

    // Commission kis user ko mila
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // Direct parent
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id', 'id');
    }

    // Jo naya user join hua
    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id', 'id');
    }
}
