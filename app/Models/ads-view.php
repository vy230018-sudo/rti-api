<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ads_id', 'count', 'status', 'is_deleted'])]
class AdsView extends Model
{
    protected $table = 'ads_view';

    protected $casts = [
        'user_id' => 'integer',
        'ads_id' => 'integer',
        'count' => 'integer',
        'status' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1)->where('is_deleted', 1);
    }
}
