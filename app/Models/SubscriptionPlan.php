<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'description',
    'credits',
    'days',
    'price',
    'offer_price',
    'status',
    'is_deleted',
])]
class SubscriptionPlan extends Model
{
    protected $table = 'subscription_ads';

    protected $casts = [
        'price' => 'decimal:2',
        'status' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_deleted', 1);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 1)->where('is_deleted', 1);
    }

    public function adsDetails(): HasMany
    {
        return $this->hasMany(ads_details::class, 'ads_sub_id', 'id');
    }
}
