<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'description', 'quiz_count', 'days', 'price', 'offer_price', 'status', 'is_deleted'])]
class QuizSubscriptionPlan extends Model
{
    protected $casts = [
        'quiz_count' => 'integer',
        'days' => 'integer',
        'price' => 'decimal:2',
        'offer_price' => 'decimal:2',
        'status' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 1)->where('is_deleted', 1);
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_deleted', 1);
    }
}
