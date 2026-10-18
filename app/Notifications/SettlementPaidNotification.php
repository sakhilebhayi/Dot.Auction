<?php

namespace App\Notifications;

use App\Models\AuctionSettlement;
use App\Notifications\Concerns\DescribesSettlement;
use Illuminate\Notifications\Notification;

/**
 * In-app notice that a winner's payment was confirmed by Paystack. To the seller it says, in so many
 * words, that the money is with Dot.Auction and that paying them out is a manual step: never that
 * they have been paid.
 */
class SettlementPaidNotification extends Notification
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
            ? $this->payload($this->settlement, 'settlement_paid', 'Payment received', "Your payment of {$amount} for {$lot} was received. The seller has been told.".$this->testModeNote())
            : $this->payload($this->settlement, 'settlement_paid_seller', 'Buyer has paid', "The buyer's payment of {$amount} for {$lot} was received by Dot.Auction. Payout is not available yet: settlement to you is manual.".$this->testModeNote());
    }
}
