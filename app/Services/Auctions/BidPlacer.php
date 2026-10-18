<?php

namespace App\Services\Auctions;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The only supported way to place a manual bid.
 *
 * Everything that decides whether a bid is legal runs inside ONE
 * transaction, after taking a row lock on the auction (SELECT ... FOR
 * UPDATE on PostgreSQL/MySQL; SQLite has no row locks but serialises
 * writers). Price, status and the bidding window are RE-READ under that
 * lock, so a caller holding a stale Auction instance (a page loaded before
 * someone else's bid committed) is judged against the truth, never against
 * its own hydrated copy. A partial unique index on bids(auction_id) WHERE
 * is_winning is the database-level backstop: even a code path that skips
 * this lock cannot persist two winners.
 *
 * BidPlaced and the outbid notification are dispatched only after commit,
 * so listeners and Reverb clients never observe a bid that later rolls back.
 */
class BidPlacer
{
    /**
     * @throws BidRejected
     */
    public function place(Auction|int $auction, User $bidder, float|int|string $amount, ?string $submissionToken = null): Bid
    {
        $auctionId = $auction instanceof Auction ? $auction->getKey() : $auction;
        $amountCents = $this->parseCents($amount);
        $tokenKey = $submissionToken !== null && $submissionToken !== ''
            ? "bid-token:{$auctionId}:{$bidder->getKey()}:{$submissionToken}"
            : null;
        $tokenClaimed = false;

        try {
            return DB::transaction(function () use ($auctionId, $bidder, $amountCents, $tokenKey, &$tokenClaimed) {
                $locked = Auction::query()->lockForUpdate()->find($auctionId);

                if ($locked === null) {
                    throw new BidRejected('This auction could not be found.');
                }

                if (! $locked->isActive()) {
                    throw new BidRejected('This auction is no longer active.');
                }

                if ($bidder->getKey() === $locked->seller_id) {
                    throw new BidRejected('You cannot bid on your own auction.');
                }

                $duplicate = Bid::query()
                    ->where('auction_id', $locked->id)
                    ->where('bidder_id', $bidder->getKey())
                    ->get(['amount'])
                    ->contains(fn (Bid $existing) => Auction::toCents($existing->amount) === $amountCents);

                if ($duplicate) {
                    throw new BidRejected('Duplicate bid: you have already bid this amount on this auction.');
                }

                if ($tokenKey !== null) {
                    if (! Cache::add($tokenKey, true, now()->addMinutes(10))) {
                        throw new BidRejected('This bid was already submitted.');
                    }
                    $tokenClaimed = true;
                }

                $minimumCents = $locked->minimumBidCents();
                if ($amountCents < $minimumCents) {
                    throw new BidRejected('Minimum bid is '.Auction::money($minimumCents / 100));
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
                    'bidder_id' => $bidder->getKey(),
                    'amount' => $this->decimal($amountCents),
                    'is_winning' => true,
                ]);

                $locked->update(['current_price' => $this->decimal($amountCents)]);
                $bid->setRelation('auction', $locked);

                $previousBidderId = $previousWinner?->bidder_id;
                DB::afterCommit(function () use ($bid, $previousBidderId) {
                    event(new BidPlaced($bid));

                    if ($previousBidderId !== null && $previousBidderId !== $bid->bidder_id) {
                        User::find($previousBidderId)?->notify(new OutbidNotification($bid));
                    }
                });

                return $bid;
            });
        } catch (\Throwable $e) {
            // A failed attempt must not burn the client's token, or the
            // bidder could never retry the same form after fixing it.
            if ($tokenClaimed && $tokenKey !== null) {
                Cache::forget($tokenKey);
            }

            throw $e;
        }
    }

    /**
     * @throws BidRejected when the amount is not a positive number of at most 2 decimals
     */
    private function parseCents(float|int|string $amount): int
    {
        if (! is_numeric($amount)) {
            throw new BidRejected('Enter a valid bid amount.');
        }

        $scaled = (float) $amount * 100;
        $cents = (int) round($scaled);

        if (abs($scaled - $cents) > 1e-6) {
            throw new BidRejected('Bid amounts can have at most 2 decimal places.');
        }

        if ($cents <= 0) {
            throw new BidRejected('Enter a valid bid amount.');
        }

        return $cents;
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
