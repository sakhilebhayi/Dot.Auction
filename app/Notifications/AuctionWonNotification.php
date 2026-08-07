<?php

namespace App\Notifications;

use App\Models\Auction;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for the winning bidder once
 * an auction settles. Not yet wired to any automatic trigger — there is no
 * settlement job yet (see wiki.md §6 roadmap: "Auction settlement job: on
 * ends_at passing, resolve winner, flip status to ended"). Dispatch
 * manually via `$user->notify(new AuctionWonNotification($auction))` until
 * that job exists.
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
            'message' => "You won \"{$this->auction->title}\" for R".number_format((float) $this->auction->current_price, 2).'.',
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
        ];
    }
}
