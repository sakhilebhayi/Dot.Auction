<?php

namespace App\Notifications;

use App\Models\Auction;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for the winning bidder once
 * an auction settles. Sent by App\Services\Auctions\AuctionSettler (and by
 * the seller's accept decision on a reserve-not-met auction). Payment is not
 * live yet, and the message says so rather than implying money is due.
 */
class AuctionWonNotification extends Notification
{
    public function __construct(public Auction $auction) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'auction_won',
            'title' => 'Auction won',
            'message' => "You won \"{$this->auction->title}\" for ".Auction::money($this->auction->current_price).'. '
                .'Payment is not yet available in Dot.Auction, so nothing is due now. You will be told when the payment step opens.',
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
        ];
    }
}
