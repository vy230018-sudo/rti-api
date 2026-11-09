<?php

namespace App\Notifications;

class NewsLikedNotification extends AppNotification
{
    public function __construct(
        public string $actorName,
        public int $newsId,
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'news_liked',
            'title' => 'New like',
            'body' => "{$this->actorName} liked your post.",
            'data' => ['news_id' => $this->newsId],
        ];
    }
}
