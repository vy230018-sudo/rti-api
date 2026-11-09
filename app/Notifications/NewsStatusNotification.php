<?php

namespace App\Notifications;

class NewsStatusNotification extends AppNotification
{
    public function __construct(
        public int $newsId,
        public string $status, // approved | rejected | updated
        public ?string $title = null,
    ) {
    }

    protected function payload(): array
    {
        $label = match ($this->status) {
            'approved' => 'approved and is now live',
            'rejected' => 'rejected',
            default => 'updated',
        };
        $name = $this->title ? ' "'.self::excerpt($this->title, 50).'"' : '';

        return [
            'type' => 'news_status',
            'title' => 'Post update',
            'body' => "Your post{$name} was {$label}.",
            'data' => ['news_id' => $this->newsId, 'status' => $this->status],
        ];
    }
}
