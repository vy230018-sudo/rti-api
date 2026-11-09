<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'plan_id', 'subscription_type', 'payment_id', 'price', 'credits', 'start_date', 'end_date', 'payment_status', 'transaction', 'status'])]
class UserPlanSubscription extends Model
{
    protected $table = 'user_plan_subscriptions';

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'credits' => 'integer', 'start_date' => 'date', 'end_date' => 'date'];
    }
}
