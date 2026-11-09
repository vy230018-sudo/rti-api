<?php

namespace App\Notifications;

class NewCommentNotification extends AppNotification
{
    public function __construct(
        public string $actorName,
        public int $newsId,
        public int $commentId,
        public ?string $comment = null,
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'new_comment',
            'title' => 'New comment',
            'body' => "{$this->actorName} commented on your post: ".self::excerpt($this->comment),
            'data' => ['news_id' => $this->newsId, 'comment_id' => $this->commentId],
        ];
    }
}
