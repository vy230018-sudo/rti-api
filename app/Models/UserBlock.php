<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class UserBlock extends Model
{
    protected $fillable = ['blocker_id', 'blocked_id'];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    /**
     * Users whose content must be hidden from the given viewer: users the
     * viewer blocked and users who blocked the viewer.
     */
    public static function hiddenUserIdsFor(int $userId): Collection
    {
        return static::query()
            ->where('blocker_id', $userId)
            ->orWhere('blocked_id', $userId)
            ->get(['blocker_id', 'blocked_id'])
            ->map(fn ($b) => (int) $b->blocker_id === $userId ? $b->blocked_id : $b->blocker_id)
            ->unique()
            ->values();
    }
}
