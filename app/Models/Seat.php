<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Seat extends Model
{
    protected $table = 'seats';

    /** status / is_deleted: 2 = reserved (payment pending), 1 = active (locked), 0 = released. */
    public const STATUS_RELEASED = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_RESERVED = 2;

    /** Payment complete na hone par seat kitni der hold rahegi. */
    public const RESERVE_MINUTES = 15;

    /** Seat lock levels, bade se chhote tak. Har level alag seat hai. */
    public const LEVELS = ['state', 'district', 'taluka', 'village'];

    protected $fillable = [
        'user_id',
        'state_seat',
        'status',
        'is_deleted',
        'plan_id',
        'role_id',
        'level',
        'state',
        'district',
        'taluka',
        'village',
        'payment_id',
        'user_subscription_id',
        'lock_key',
        'starts_at',
        'expires_at',
        'reserved_until',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'datetime',
            'reserved_until' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public static function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    /**
     * Role + level + location ka fingerprint. seats.lock_key par UNIQUE index hai,
     * isliye same seat ko 2 users ek saath nahi le sakte (DB level guarantee).
     */
    public static function makeLockKey(string $roleId, string $level, array $location): string
    {
        $parts = [self::normalize($roleId), $level];

        foreach (self::LEVELS as $name) {
            $parts[] = self::normalize($location[$name] ?? '');

            if ($name === $level) {
                break;
            }
        }

        return sha1(implode('|', $parts));
    }

    public function computeLockKey(): ?string
    {
        if (blank($this->role_id) || blank($this->level)) {
            return null;
        }

        return self::makeLockKey((string) $this->role_id, (string) $this->level, [
            'state' => $this->state,
            'district' => $this->district,
            'taluka' => $this->taluka,
            'village' => $this->village,
        ]);
    }

    /** Active seats jinki validity khatam ho chuki hai. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('lock_key')
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    /** Reserved seats jinka payment window nikal chuka hai. */
    public function scopeStaleReservation(Builder $query): Builder
    {
        return $query->whereNotNull('lock_key')
            ->where('status', self::STATUS_RESERVED)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now());
    }

    public static function releaseExpired(?string $lockKey = null): int
    {
        $query = static::query()->expired();

        if ($lockKey !== null) {
            $query->where('lock_key', $lockKey);
        }

        return $query->update(self::releasedAttributes());
    }

    public static function releaseStaleReservations(?string $lockKey = null): int
    {
        $query = static::query()->staleReservation();

        if ($lockKey !== null) {
            $query->where('lock_key', $lockKey);
        }

        return $query->update(self::releasedAttributes());
    }

    public function release(): void
    {
        $this->forceFill(self::releasedAttributes())->save();
    }

    protected static function releasedAttributes(): array
    {
        return [
            'lock_key' => null,
            'status' => self::STATUS_RELEASED,
            'is_deleted' => 0,
            'released_at' => now(),
        ];
    }
}
