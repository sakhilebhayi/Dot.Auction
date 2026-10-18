<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notice to the seller that their auction
 * sold. Says plainly that payment collection is not live: the sale is only
 * recorded as awaiting payment.
 */
class AuctionSoldNotification extends Notification
{
    public function __construct(public Auction $auction, public AuctionSettlement $settlement) {}

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
            'type' => 'auction_sold',
            'title' => 'Auction sold',
            'message' => "\"{$this->auction->title}\" sold for {$this->settlement->amount()}. "
                .'Payment is not yet available in Dot.Auction, so the sale is recorded as awaiting payment. '
                .'You will be told when the payment step opens.',
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
        ];
    }
}
