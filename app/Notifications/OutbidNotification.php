<?php

namespace App\Notifications;

use App\Models\Bid;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\Notify\NotifyClient;
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
    use SendsNotifyEvent;

    public function __construct(public Bid $newBid) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', ...$this->notifyChannel()];
    }

    /**
     * The email-worthy copy of this alert for Dot.Notify (auction.outbid), for the displaced bidder
     * only. The new bid id is the bucket, so a retry collapses while the next outbid is a new event.
     *
     * @return array{event: string, subject: int, bucket: int, data: array<string, string>}|null
     */
    public function toNotifyEvent(object $notifiable): ?array
    {
        $auction = $this->newBid->auction;
        $notify = app(NotifyClient::class);

        // The recipient's own highest bid (the one just beaten).
        $yourBid = Bid::query()->where('auction_id', $auction->id)->where('bidder_id', $notifiable->getKey())->max('amount');

        if ($yourBid === null) {
            return null;
        }

        return [
            'event' => 'auction.outbid',
            'subject' => $auction->id,
            'bucket' => $this->newBid->id,
            'data' => [
                'auction_title' => (string) $auction->title,
                'your_bid' => $notify->money($yourBid),
                'current_price' => $notify->money($auction->current_price),
                'currency' => (string) config('auction.currency_code', 'ZAR'),
                'ends_at' => $auction->ends_at->toIso8601String(),
                'url' => $notify->pageUrl('auctions.show', $auction),
            ],
        ];
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
