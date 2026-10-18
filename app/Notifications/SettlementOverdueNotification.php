<?php

namespace App\Notifications;

use App\Models\AuctionSettlement;
use App\Notifications\Concerns\DescribesSettlement;
use Illuminate\Notifications\Notification;

/** In-app notice that a sale's payment deadline passed without payment. Nothing was relisted or cancelled. */
class SettlementOverdueNotification extends Notification
{
    use DescribesSettlement;

    /** @param  'winner'|'seller'  $role */
    public function __construct(public AuctionSettlement $settlement, public string $role) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $lot = $this->lot($this->settlement);
        $amount = $this->settlement->amount();

        return $this->role === 'winner'
            ? $this->payload($this->settlement, 'settlement_overdue', 'Payment overdue', "Payment of {$amount} for {$lot} is overdue. You can still pay until the seller cancels the sale.")
            : $this->payload($this->settlement, 'settlement_overdue_seller', 'Payment overdue', "The buyer has not paid {$amount} for {$lot} by the deadline. You can keep waiting or cancel this sale from its payment page. Dot.Auction does not relist or cancel it for you.");
    }
}
