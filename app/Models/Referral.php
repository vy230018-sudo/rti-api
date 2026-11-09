<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'from_user_id', 'refral_code', 'amount', 'level', 'status', 'is_deleted'])]
class Referral extends Model
{
    protected $table = 'referal';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id', 'id');
    }
}
