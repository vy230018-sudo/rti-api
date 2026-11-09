<?php

namespace App\Notifications;

class IdCardStatusNotification extends AppNotification
{
    public function __construct(
        public int $idCardId,
        public string $status,
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'id_card_status',
            'title' => 'ID card update',
            'body' => "Your ID card status is now {$this->status}.",
            'data' => ['id_card_id' => $this->idCardId, 'status' => $this->status],
        ];
    }
}
