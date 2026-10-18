<?php

namespace App\Listeners;

use App\Events\AuctionSettled;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Notifications\AuctionSoldNotification;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The hand-off seam to the payment platform. When an auction settles with a
 * winner this records one auction_settlements row in `awaiting_payment` and
 * tells the seller the sale is recorded. It moves no money itself. When
 * Paystack is configured the settlement also gets its payment deadline now;
 * when it is not, the deadline stays empty (the winner could not pay) and
 * starts counting once payment is switched on (SettlementLifecycle).
 *
 * Idempotent: the unique index on auction_id means a replayed event (queue
 * retry, manual re-dispatch) neither creates a second row nor sends a
 * second seller notice.
 */
class RecordSettlementForPayment implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(AuctionSettled $event): void
    {
        if ($event->winningBid === null) {
            return;
        }

        $auction = $event->auction;
        $payments = app(PaystackConfig::class);

        try {
            $settlement = AuctionSettlement::firstOrCreate(
                ['auction_id' => $auction->id],
                [
                    'winning_bid_id' => $event->winningBid->id,
                    'seller_id' => $auction->seller_id,
                    'winner_id' => $event->winningBid->bidder_id,
                    'amount_cents' => Auction::toCents($auction->current_price),
                    'currency' => (string) config('auction.currency_code', 'ZAR'),
                    'status' => AuctionSettlement::AWAITING_PAYMENT,
                    'payment_due_at' => $payments->canTakePayments() ? now()->addHours($payments->paymentWindowHours()) : null,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if (! $settlement->wasRecentlyCreated) {
            return;
        }

        $auction->seller?->notify(new AuctionSoldNotification($auction, $settlement));
    }
}
