<?php

namespace App\Notifications;

class EcomEnquiryNotification extends AppNotification
{
    public function __construct(
        public string $actorName,
        public int $ecomId,
        public int $enquiryId,
        public ?string $product = null,
    ) {
    }

    protected function payload(): array
    {
        return [
            'type' => 'ecom_enquiry',
            'title' => 'New enquiry',
            'body' => "{$this->actorName} sent an enquiry".($this->product ? ' for '.self::excerpt($this->product, 40) : '').'.',
            'data' => ['ecom_id' => $this->ecomId, 'enquiry_id' => $this->enquiryId],
        ];
    }
}
