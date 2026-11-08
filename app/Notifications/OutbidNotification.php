<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\Bid;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\DisplayTime;
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

    /**
     * @param  bool  $buyNow  the new bid is a Buy Now purchase that ended the lot: the displaced bidder was not outbid, the lot was sold from under them
     */
    public function __construct(public Bid $newBid, public bool $buyNow = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // One unread notice per lot per person: a second outbid on the same lot rewrites the unread one (newest price, back to the top)
        // instead of adding a row, so a bidding war is one line, not eight. The live broadcast and the e-mail still go out each time.
        $channels = ['broadcast', ...$this->notifyChannel()];

        if (! $this->collapseIntoUnread($notifiable)) {
            array_unshift($channels, 'database');
        }

        return $channels;
    }

    /**
     * Opening the lot is the answer to "you were outbid": mark this person's unread outbid notices for it as read.
     */
    public static function markReadFor(object $user, int $auctionId): void
    {
        $user->unreadNotifications()
            ->where('type', static::class)
            ->where('data', 'like', '%"auction_id":'.$auctionId.'%')
            ->limit(50)->get()
            ->filter(fn ($n) => (int) ($n->data['auction_id'] ?? 0) === $auctionId)
            ->each->markAsRead();
    }

    /**
     * Rewrite this person's unread notice about this lot in place. Returns whether there was one.
     */
    private function collapseIntoUnread(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'unreadNotifications')) {
            return false;
        }

        $auctionId = $this->newBid->auction_id;
        $candidates = $notifiable->unreadNotifications()
            ->where('type', static::class)
            ->where('data', 'like', '%"auction_id":'.$auctionId.'%')
            ->latest()->limit(20)->get();

        $existing = $candidates->first(fn ($n) => (int) ($n->data['auction_id'] ?? 0) === (int) $auctionId);

        if ($existing === null) {
            return false;
        }

        $existing->forceFill(['data' => $this->toDatabase($notifiable), 'created_at' => now(), 'updated_at' => now()])->save();

        // Any other unread duplicates of the same lot (from before this rule) are folded away.
        $candidates->reject(fn ($n) => $n->getKey() === $existing->getKey())
            ->filter(fn ($n) => (int) ($n->data['auction_id'] ?? 0) === (int) $auctionId)
            ->each->markAsRead();

        return true;
    }

    /**
     * The email-worthy copy of this alert for Dot.Notify (auction.outbid), for the displaced bidder
     * only. The new bid id is the bucket, so a retry collapses while the next outbid is a new event.
     *
     * @return array{event: string, subject: int, bucket: int, data: array<string, string>}|null
     */
    public function toNotifyEvent(object $notifiable): ?array
    {
        // Needs owner (docs/R42_DECISIONS.md, polish section): Dot.Notify has only the auction.outbid template, so a Buy Now sale still
        // e-mails the displaced bidder through it. The in-app notice is the honest one; a lost-to-Buy-Now template would replace this.
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
                // Same instant as UTC, written with the display-zone offset (+02:00) for the e-mail's reader.
                'ends_at' => DisplayTime::iso($auction->ends_at),
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

        if ($this->buyNow) {
            return [
                'type' => 'outbid',
                'title' => 'Sold to another bidder by Buy Now',
                'message' => "\"{$auction->title}\" was bought by another bidder with Buy Now for ".Auction::money($this->newBid->amount).'. Bidding has ended.',
                'auction_id' => $auction->id,
                'url' => route('auctions.show', $auction),
            ];
        }

        return [
            'type' => 'outbid',
            'title' => "You've been outbid",
            'message' => "Someone bid higher on \"{$auction->title}\". The price is now ".Auction::money($auction->current_price).' and the next valid bid is '.Auction::money($auction->minimumBidCents() / 100).'.',
            'auction_id' => $auction->id,
            'url' => route('auctions.show', $auction),
        ];
    }
}
