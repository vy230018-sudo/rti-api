<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'request_code',
    'user_id',
    'amount',
    'fee',
    'tds_amount',
    'net_amount',
    'method',
    'account_holder_name',
    'account_number',
    'ifsc_code',
    'bank_name',
    'upi_id',
    'pan_number',
    'status',
    'payout_reference',
    'payout_mode',
    'admin_remarks',
    'rejection_reason',
    'processed_by',
    'requested_at',
    'approved_at',
    'paid_at',
    'rejected_at',
    'meta',
    'is_deleted',
])]
#[Hidden(['pan_number'])]
class WithdrawalRequest extends Model
{
    protected $table = 'withdrawal_requests';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'meta' => 'array',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** Wo statuses jinme paisa abhi bhi block hai. */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PROCESSING]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /** Account number ko masked form me dikhana (admin list ke liye). */
    public function maskedAccountNumber(): ?string
    {
        if (! $this->account_number) {
            return null;
        }

        return str_repeat('X', max(0, strlen($this->account_number) - 4)).substr($this->account_number, -4);
    }
}