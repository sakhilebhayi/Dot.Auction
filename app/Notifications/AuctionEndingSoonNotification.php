<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for watchers of an auction
 * that is about to close. Sent by `auction:notify-ending-soon`, which
 * records on the watchlist row that it has told that watcher, so each
 * watcher hears once per auction.
 */
class AuctionEndingSoonNotification extends Notification
{
    use SendsNotifyEvent;

    public function __construct(public Auction $auction) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /**
     * The email-worthy copy of this notice for Dot.Notify (auction.ending_soon). Once per watcher
     * per auction, which the command already guarantees, so the bucket is fixed.
     *
     * @return array{event: string, subject: int, bucket: string, data: array<string, string>}
     */
    public function toNotifyEvent(object $notifiable): array
    {
        $notify = app(NotifyClient::class);

        return [
            'event' => 'auction.ending_soon',
            'subject' => $this->auction->id,
            'bucket' => 'ending-soon',
            'data' => [
                'auction_title' => (string) $this->auction->title,
                'current_price' => $notify->money($this->auction->current_price),
                'currency' => (string) config('auction.currency_code', 'ZAR'),
                'ends_at' => $this->auction->ends_at->toIso8601String(),
                'url' => $notify->pageUrl('auctions.show', $this->auction),
            ],
        ];
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
