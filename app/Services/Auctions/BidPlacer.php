<?php

namespace App\Services\Auctions;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;
use Carbon\CarbonInterface;
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
 * A bid in the closing minutes also moves ends_at (anti-snipe) inside that
 * same transaction -- see snipeExtension().
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
            // The token comes from the browser, so it is hashed: any length or character set stays a fixed-size cache key
            // (the database cache's key column is 255 characters; a 10 kB token was a 500).
            ? "bid-token:{$auctionId}:{$bidder->getKey()}:".hash('sha256', $submissionToken)
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

                $bid = Bid::forceCreate([
                    'auction_id' => $locked->id,
                    'bidder_id' => $bidder->getKey(),
                    'amount' => $this->decimal($amountCents),
                    'is_winning' => true,
                ]);

                // Anti-snipe: decided from the LOCKED row, and written in the
                // same UPDATE as the new price, so the bid and the moved
                // close commit (or roll back) together.
                $locked->forceFill(array_merge(
                    ['current_price' => $this->decimal($amountCents)],
                    $this->snipeExtension($locked),
                ))->save();
                $bid->setRelation('auction', $locked);

                $previousBidderId = $previousWinner?->bidder_id;
                DB::afterCommit(function () use ($bid, $previousBidderId) {
                    AuctionSnapshot::forget($bid->auction_id);
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
     * The ends_at / snipe_extensions change a bid placed right now earns,
     * or [] when none applies. A bid inside the final `snipe_window_minutes`
     * (the exact boundary counts) pushes the close out by
     * `snipe_extension_minutes`, until `snipe_max_extensions` is used up.
     *
     * @return array{ends_at?: CarbonInterface, snipe_extensions?: int}
     */
    private function snipeExtension(Auction $locked): array
    {
        $window = (int) config('auction.snipe_window_minutes', 5);
        $extension = (int) config('auction.snipe_extension_minutes', 5);
        $max = (int) config('auction.snipe_max_extensions', 6);

        if ($window <= 0 || $extension <= 0 || $locked->snipe_extensions >= $max) {
            return [];
        }

        if ($locked->ends_at->greaterThan(now()->addMinutes($window))) {
            return [];
        }

        return [
            'ends_at' => $locked->ends_at->copy()->addMinutes($extension),
            'snipe_extensions' => $locked->snipe_extensions + 1,
        ];
    }

    /**
     * @throws BidRejected when the amount is not a positive, finite number of at most 2 decimals, or is above the
     *                     platform price ceiling (the same one sellers are held to), which also keeps it inside decimal(12,2)
     */
    private function parseCents(float|int|string $amount): int
    {
        if (! is_numeric($amount)) {
            throw new BidRejected('Enter a valid bid amount.');
        }

        $scaled = (float) $amount * 100;

        // INF/NAN (typed, or an exponent such as 1e308 overflowing the multiplication) and anything that is not at
        // least one cent never reach an int cast or the database.
        if (! is_finite($scaled) || $scaled < 0.5) {
            throw new BidRejected('Enter a valid bid amount.');
        }

        $ceilingCents = max(1, (int) config('auction.listing.max_price', 100000000)) * 100;

        if ($scaled > $ceilingCents + 0.5) {
            throw new BidRejected('Bids above '.Auction::money($ceilingCents / 100).' are not accepted.');
        }

        $cents = (int) round($scaled);

        if (abs($scaled - $cents) > 1e-6) {
            throw new BidRejected('Bid amounts can have at most 2 decimal places.');
        }

        return $cents;
    }

    private function decimal(int $cents): string
    {
        return Auction::fromCents($cents);
    }
}
