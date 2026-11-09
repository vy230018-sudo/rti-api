<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'sub_id', 'title', 'description', 'credits', 'days', 'price', 'offer_price', 'status', 'is_deleted'])]
class EcomSubscription extends Model
{
    protected $table = 'ecom_subscription';

    protected $casts = [
        'user_id' => 'integer',
        'sub_id' => 'integer',
        'credits' => 'integer',
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

    public function ecomDetails(): HasMany
    {
        return $this->hasMany(EcomDetail::class, 'ecom_sub_id', 'id');
    }
}
