<?php

namespace App\Listeners;

use App\Events\AuctionSettled;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Notifications\AuctionSoldNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The hand-off seam to the payment platform. When an auction settles with a
 * winner this records one auction_settlements row in `awaiting_payment` and
 * tells the seller the sale is recorded. It integrates no payment provider
 * and moves no money: the provider has not been chosen, and the winner and
 * seller are told exactly that.
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
