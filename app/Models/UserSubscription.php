<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'plan_id', 'price', 'start_date', 'end_date', 'payment_status', 'transaction', 'status', 'is_deleted'])]
class UserSubscription extends Model
{
    protected $table = 'user_subscription';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RoleSubscriptionPlan::class, 'plan_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 1)
            ->where('payment_status', 1)
            ->where('is_deleted', 1)
            ->where(function (Builder $query): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
            });
    }
}
