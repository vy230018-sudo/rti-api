<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCommissionRate extends Model
{
    protected $fillable = ['level', 'percentage', 'status'];

    protected $casts = ['percentage' => 'decimal:2', 'status' => 'boolean'];
}
