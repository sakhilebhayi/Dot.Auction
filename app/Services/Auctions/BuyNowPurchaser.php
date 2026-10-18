<?php

namespace App\Services\Auctions;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;
use Illuminate\Support\Facades\DB;

/**
 * Takes an auction's Buy Now option.
 *
 * Mirrors BidPlacer: one transaction, the auction row locked first, and
 * every rule judged against the freshly read row rather than a stale page.
 * A purchase is recorded as an ordinary winning bid at exactly the Buy Now
 * price, the close is pulled to now, and the lot then goes through
 * AuctionSettler like any other ended auction -- so the winner
 * notification, the AuctionSettled event and the settlement hand-off all
 * come from the one existing, idempotent settlement path rather than a
 * second copy of it.
 */
class BuyNowPurchaser
{
    public function __construct(private readonly AuctionSettler $settler) {}

    /**
     * @throws BidRejected
     */
    public function buy(Auction|int $auction, User $buyer): Bid
    {
        $auctionId = $auction instanceof Auction ? $auction->getKey() : $auction;

        return DB::transaction(function () use ($auctionId, $buyer) {
            $locked = Auction::query()->lockForUpdate()->find($auctionId);

            if ($locked === null) {
                throw new BidRejected('This auction could not be found.');
            }

            if (! $locked->isActive()) {
                throw new BidRejected('This auction is no longer active.');
            }

            if ($locked->buy_now_price === null) {
                throw new BidRejected('This auction has no Buy Now price.');
            }

            if ($buyer->getKey() === $locked->seller_id) {
                throw new BidRejected('You cannot buy your own auction.');
            }

            $buyNowCents = Auction::toCents($locked->buy_now_price);

            if ($locked->reserve_price !== null && Auction::toCents($locked->reserve_price) > $buyNowCents) {
                throw new BidRejected('Buy Now is not available on this auction.');
            }

            if (Auction::toCents($locked->current_price) >= $buyNowCents) {
                throw new BidRejected('Buy Now is no longer available: bidding has reached the Buy Now price.');
            }

            $previousWinner = Bid::query()
                ->where('auction_id', $locked->id)
                ->where('is_winning', true)
                ->lockForUpdate()
                ->first();

            Bid::query()
                ->where('auction_id', $locked->id)
                ->where('is_winning', true)
                ->update(['is_winning' => false]);

            $bid = Bid::create([
                'auction_id' => $locked->id,
                'bidder_id' => $buyer->getKey(),
                'amount' => Auction::fromCents($buyNowCents),
                'is_winning' => true,
            ]);

            $locked->update([
                'current_price' => Auction::fromCents($buyNowCents),
                'ends_at' => now(),
            ]);
            $bid->setRelation('auction', $locked);

            if (! $this->settler->settle($locked)) {
                // Cannot happen with the checks above; if it ever did, the
                // whole purchase rolls back rather than leave a half-ended lot.
                throw new BidRejected('This purchase could not be completed. Nothing was charged or changed.');
            }

            // The settler flipped the status on its own locked copy.
            $locked->refresh();

            $previousBidderId = $previousWinner?->bidder_id;
            DB::afterCommit(function () use ($bid, $previousBidderId) {
                event(new BidPlaced($bid));

                if ($previousBidderId !== null && $previousBidderId !== $bid->bidder_id) {
                    User::find($previousBidderId)?->notify(new OutbidNotification($bid));
                }
            });

            return $bid;
        });
    }
}
