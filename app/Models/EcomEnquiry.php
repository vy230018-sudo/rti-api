<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ecom_id', 'type', 'first_name', 'last_name', 'email', 'contact', 'qty', 'message', 'status', 'enq_status', 'is_deleted'])]
class EcomEnquiry extends Model
{
    protected $table = 'ecom_enquiry';

    const UPDATED_AT = 'update_at';

    protected $casts = [
        'user_id' => 'integer',
        'ecom_id' => 'integer',
        'status' => 'integer',
        'enq_status' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(EcomDetail::class, 'ecom_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1)->where('is_deleted', 1);
    }
}
