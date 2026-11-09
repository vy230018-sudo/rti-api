<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ads_sub_id', 'image', 'media_type', 'title', 'description', 'redirection', 'redirection_url', 'start_date', 'end_date', 'status', 'is_deleted'])]
class ads_details extends Model
{
    protected $table = 'ads_deatils';

    protected $casts = [
        'user_id' => 'string',
        'ads_sub_id' => 'string',
        'image' => 'string',
        'media_type' => 'integer', // 1 = image, 2 = video
        'title' => 'string',
        'description' => 'string',
        'redirection' => 'integer',
        'redirection_url' => 'string',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /** Jis Advertisement Subscription Plan (subscription_ads) ke through ad post hua. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'ads_sub_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1)->where('is_deleted', 1);
    }
}
