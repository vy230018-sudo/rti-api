<?php

namespace App\Notifications;

class ProfileReviewNotification extends AppNotification
{
    public function __construct(public bool $approved)
    {
    }

    protected function payload(): array
    {
        return [
            'type' => 'profile_review',
            'title' => 'Profile update',
            'body' => $this->approved
                ? 'Your profile changes were approved.'
                : 'Your profile changes were rejected. Please review and submit again.',
            'data' => ['approved' => $this->approved ? 1 : 0],
        ];
    }
}
