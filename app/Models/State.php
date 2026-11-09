<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'state', 'district', 'taluka', 'village', 'state_id', 'district_id', 'taluka_id', 'village_id', 'status', 'id_deleted'])]
class State extends Model
{
    protected $table = 'user_state';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
