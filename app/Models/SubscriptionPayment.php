<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'plan_id', 'subscription_type', 'user_subscription_id', 'user_plan_subscription_id', 'razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature', 'amount', 'currency', 'status', 'failure_code', 'failure_description', 'gateway_response'])]
class SubscriptionPayment extends Model
{
    protected $table = 'subscription_payments';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_response' => 'array',
        ];
    }
}
