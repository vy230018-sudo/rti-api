<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sab notifications ka base. Har notification:
 *  1) database me save hoti hai (app ki notification list screen ke liye)
 *  2) FCM push ke roop me phone / browser par jaati hai
 * Child class sirf payload() define karti hai.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array{type:string,title:string,body:string,data?:array} */
    abstract protected function payload(): array;

    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toFcm(object $notifiable): array
    {
        $p = $this->payload();

        return [
            'title' => $p['title'],
            'body' => $p['body'],
            'data' => array_merge(['type' => $p['type']], $p['data'] ?? []),
        ];
    }

    /** Khud ko ya block kiye hue user ko notification nahi bhejni. */
    public static function allowed(int $actorId, int $recipientId): bool
    {
        if ($actorId === $recipientId) {
            return false;
        }

        return ! UserBlock::hiddenUserIdsFor($recipientId)->contains($actorId);
    }


    /**
     * Ek line me notification bhejne ke liye. Self-notify / blocked user ko skip karta hai
     * aur kabhi exception nahi fenkta (notification fail hone se main API break nahi honi chahiye).
     */
    public static function dispatchTo(int|string|null $recipientId, int|string $actorId, self $notification): void
    {
        if (! $recipientId || ! self::allowed((int) $actorId, (int) $recipientId)) {
            return;
        }

        self::dispatchSystem($recipientId, $notification);
    }

    /** Actor ke bina (admin action, system event). */
    public static function dispatchSystem(int|string|null $recipientId, self $notification): void
    {
        if (! $recipientId) {
            return;
        }

        try {
            User::query()->find((int) $recipientId)?->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function nameOf(User $user): string
    {
        return trim($user->firstname.' '.$user->lastname) ?: 'Someone';
    }

    protected static function excerpt(?string $text, int $limit = 80): string
    {
        return Str::limit(trim((string) $text), $limit);
    }
}