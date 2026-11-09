<?php

namespace App\Notifications;

class CommentReplyNotification extends AppNotification
{
    public function __construct(
        public string $actorName,
        public int $newsId,
        public int $commentId,
        public ?string $reply = null,
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'comment_reply',
            'title' => 'New reply',
            'body' => "{$this->actorName} replied to your comment: ".self::excerpt($this->reply),
            'data' => ['news_id' => $this->newsId, 'comment_id' => $this->commentId],
        ];
    }
}
