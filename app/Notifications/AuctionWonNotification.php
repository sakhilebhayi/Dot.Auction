<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\Bid;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\Notify\NotifyClient;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for the winning bidder once
 * an auction settles. Sent by App\Services\Auctions\AuctionSettler (and by
 * the seller's accept decision on a reserve-not-met auction). Payment is not
 * live yet, and the message says so rather than implying money is due.
 */
class AuctionWonNotification extends Notification
{
    use SendsNotifyEvent;

    /** @param  Bid|null  $winningBid  names the occurrence for Dot.Notify's de-duplication; looked up when omitted */
    public function __construct(public Auction $auction, public ?Bid $winningBid = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /**
     * The email-worthy copy of this notice for Dot.Notify (auction.won). The winning bid id is the
     * bucket: an auction has one winning bid, so a replay collapses.
     *
     * @return array{event: string, subject: int, bucket: string|int, data: array<string, string>}
     */
    public function toNotifyEvent(object $notifiable): array
    {
        $notify = app(NotifyClient::class);

        return [
            'event' => 'auction.won',
            'subject' => $this->auction->id,
            'bucket' => $this->winningBid->id ?? $this->auction->winningBid()->value('id') ?? 'won',
            'data' => [
                'auction_title' => (string) $this->auction->title,
                'final_price' => $notify->money($this->auction->current_price),
                'currency' => (string) config('auction.currency_code', 'ZAR'),
                'url' => $notify->pageUrl('auctions.show', $this->auction),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payments = app(PaystackConfig::class);

        // With online payment on, say what is due and by when; with it off, the original honest notice.
        $payment = $payments->canTakePayments()
            ? "Pay within {$payments->paymentWindowHours()} hours from the auction page."
                .($payments->isTestMode() ? ' Payments are in test mode: no real money moves.' : '')
            : 'Payment is not yet available in Dot.Auction, so nothing is due now. You will be told when the payment step opens.';

        return [
            'type' => 'auction_won',
            'title' => 'Auction won',
            'message' => "You won \"{$this->auction->title}\" for ".Auction::money($this->auction->current_price).'. '.$payment,
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
        ];
    }
}
