<?php

namespace App\Notifications;

class WithdrawalStatusNotification extends AppNotification
{
    public function __construct(
        public int $withdrawalId,
        public string $status, // approved | paid | rejected
        public float|string $amount,
        public ?string $reason = null,
    ) {
    }

    protected function payload(): array
    {
        $amount = '₹'.number_format((float) $this->amount, 2);

        $body = match ($this->status) {
            'approved' => "Your withdrawal request of {$amount} was approved.",
            'paid' => "Your withdrawal of {$amount} has been paid.",
            'rejected' => "Your withdrawal request of {$amount} was rejected".($this->reason ? ': '.self::excerpt($this->reason) : '.'),
            default => "Your withdrawal request of {$amount} was updated.",
        };

        return [
            'type' => 'withdrawal_status',
            'title' => 'Wallet update',
            'body' => $body,
            'data' => ['withdrawal_id' => $this->withdrawalId, 'status' => $this->status],
        ];
    }
}
