<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['firstname', 'lastname', 'email', 'phone', 'pending_phone', 'bio', 'pending_bio', 'pending_profile_image', 'profile_update_status', 'profile_image', 'profile_image_change_count', 'referral_code','email_verified_at', 'status', 'is_deleted', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class, 'user_id', 'id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'from_user_id', 'id');
    }

    public function referredBy(): HasMany
    {
        return $this->hasMany(Referral::class, 'user_id', 'id');
    }

    public function referralCode(): string
    {
        return 'RTI'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    public function quizAttempts()
{
    return $this->hasMany(QuizAttempt::class, 'user_id');
}

    public function following(): HasMany
    {
        return $this->hasMany(UserFollow::class, 'follower_id');
    }

    public function followers(): HasMany
    {
        return $this->hasMany(UserFollow::class, 'following_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }
}
