<?php

namespace App\Notifications;

class AdminBroadcastNotification extends AppNotification
{
    public function __construct(
        public string $heading,
        public string $message,
        public array $extra = [],
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'admin_broadcast',
            'title' => $this->heading,
            'body' => $this->message,
            'data' => $this->extra,
        ];
    }
}
