<?php

namespace App\Notifications;

class NewFollowerNotification extends AppNotification
{
    public function __construct(
        public string $actorName,
        public int $actorId,
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'new_follower',
            'title' => 'New follower',
            'body' => "{$this->actorName} started following you.",
            'data' => ['user_id' => $this->actorId],
        ];
    }
}
