<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notice to the seller that their auction
 * sold. Says plainly that payment collection is not live: the sale is only
 * recorded as awaiting payment.
 */
class AuctionSoldNotification extends Notification
{
    use SendsNotifyEvent;

    public function __construct(public Auction $auction, public AuctionSettlement $settlement) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /**
     * The email-worthy copy of this notice for Dot.Notify (auction.sold). The settlement id is the
     * bucket: the settlement row is unique per auction, so a replay collapses. The winner's
     * identity is never included.
     *
     * @return array{event: string, subject: int, bucket: string, data: array<string, string>}
     */
    public function toNotifyEvent(object $notifiable): array
    {
        $notify = app(NotifyClient::class);

        return [
            'event' => 'auction.sold',
            'subject' => $this->auction->id,
            'bucket' => (string) $this->settlement->id,
            'data' => [
                'auction_title' => (string) $this->auction->title,
                'final_price' => $notify->money($this->settlement->amount_cents / 100),
                'currency' => (string) $this->settlement->currency,
                // A settlement starts as awaiting_payment; to the seller that is simply "sold".
                'status' => $this->settlement->status === AuctionSettlement::AWAITING_PAYMENT ? 'sold' : (string) $this->settlement->status,
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
