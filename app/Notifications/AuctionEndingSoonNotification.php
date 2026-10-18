<?php

namespace App\Notifications;

use App\Models\Auction;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for watchers of an auction
 * that is about to close. Sent by `auction:notify-ending-soon`, which
 * records on the watchlist row that it has told that watcher, so each
 * watcher hears once per auction.
 */
class AuctionEndingSoonNotification extends Notification
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
            'type' => 'auction_ending_soon',
            'title' => 'Auction ending soon',
            'message' => "\"{$this->auction->title}\" ends {$this->auction->ends_at->diffForHumans()}.",
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
        ];
    }
}
