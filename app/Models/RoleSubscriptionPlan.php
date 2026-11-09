<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plan_type', 'role_id', 'location_level', 'title', 'description', 'state', 'district', 'taluka', 'village', 'price', 'offer_price', 'credits', 'hide_ads', 'days', 'start_date', 'end_date', 'status', 'id_deleted'])]
class RoleSubscriptionPlan extends Model
{
    protected $table = 'subscription_plan';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'integer',
        'id_deleted' => 'integer',
        'hide_ads' => 'boolean',
    ];

    public const TYPE_ROLE = 'role';
    public const TYPE_PREMIUM = 'premium';

    /**
     * Premium Plan ek general plan hai - is par Role aur Seat Lock lagu nahi hota.
     * plan_type = premium ya title = "Premium Plan" dono me se koi bhi ho to premium.
     */
    public static function isPremiumPayload(?string $planType, ?string $title): bool
    {
        return strtolower(trim((string) $planType)) === self::TYPE_PREMIUM
            || strtolower(trim((string) $title)) === 'premium plan';
    }

    public function isPremium(): bool
    {
        return self::isPremiumPayload($this->plan_type, $this->title);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 1)->where('id_deleted', 1);
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('id_deleted', 1);
    }
}
