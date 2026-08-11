<?php

namespace App\Notifications;

use App\Models\Bid;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to the previous highest
 * bidder the moment someone else places a higher bid. Dispatched
 * automatically from App\Livewire\Auctions\BidPanel::placeBid() — this is
 * a real, live-triggered event (no settlement job or scheduler required),
 * unlike AuctionWonNotification / AuctionEndingSoonNotification below.
 *
 * Also broadcasts over Reverb so NotificationBell.php updates live instead
 * of only on page load -- see routes/channels.php for the
 * App.Models.User.{id} channel this uses. Distinct from BidPlaced, which
 * broadcasts publicly on auction.{id} for the bid panel itself; this is
 * the private, per-bidder "you've been outbid" alert.
 */
class OutbidNotification extends Notification
{
    public function __construct(public Bid $newBid) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $auction = $this->newBid->auction;

        return [
            'type' => 'outbid',
            'title' => "You've been outbid",
            'message' => "Someone placed a higher bid on \"{$auction->title}\" — new price R".number_format((float) $auction->current_price, 2).'.',
            'auction_id' => $auction->id,
            'url' => route('auctions.show', $auction),
        ];
    }
}
