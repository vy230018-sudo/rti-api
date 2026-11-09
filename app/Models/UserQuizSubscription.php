<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'quiz_subscription_plan_id', 'payment_id', 'price', 'quiz_count', 'start_date', 'end_date', 'payment_status', 'transaction', 'status'])]
class UserQuizSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quiz_count' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'payment_status' => 'integer',
            'status' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(QuizSubscriptionPlan::class, 'quiz_subscription_plan_id');
    }
}
