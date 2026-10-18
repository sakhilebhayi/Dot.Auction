<?php

namespace App\Notifications;

use App\Models\AuctionSettlement;
use App\Notifications\Concerns\DescribesSettlement;
use Illuminate\Notifications\Notification;

/** In-app notice to the winner that the seller cancelled an overdue sale. Nothing is due. */
class SettlementCancelledNotification extends Notification
{
    use DescribesSettlement;

    public function __construct(public AuctionSettlement $settlement) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            $this->settlement,
            'settlement_cancelled',
            'Sale cancelled',
            'The seller cancelled the sale of '.$this->lot($this->settlement).' because payment was overdue. Nothing is due and you cannot pay for it any more.',
        );
    }
}
