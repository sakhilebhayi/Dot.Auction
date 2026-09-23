<?php

namespace App\Services\Auctions;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;

/**
 * Proxy (eBay-style) bidding: bids.is_auto_bid has existed since the
 * original schema but nothing ever read it -- every bid was a one-shot
 * manual amount. This engine makes it real, additively:
 *
 * - A plain (is_auto_bid=false) bid behaves EXACTLY as before when it isn't
 *   competing against a standing auto-bid: it wins outright at the exact
 *   amount submitted, current_price = that amount. Every existing
 *   manual-vs-manual test path is untouched by this change.
 * - An auto-bid stores the bidder's true maximum in `amount` but never
 *   reveals it: the displayed current_price only ever rises to just enough
 *   to beat the next-best competing bid by one increment (or to the bare
 *   minimum bid if there's no competition yet).
 * - A standing auto-bid automatically "defends" itself against any later
 *   bid (manual or auto) that doesn't exceed its hidden max -- the later
 *   bid is recorded as non-winning and current_price ratchets up against
 *   it, with no action required from the auto-bidder.
 *
 * No new columns or migrations: the existing winning auto-bid Bid row IS
 * the standing instruction (its `amount` is the hidden max); this engine
 * only ever mutates Auction.current_price, which was already a mutable
 * "current auction state" field, never the ledger rows themselves.
 */
class AutoBidEngine
{
    public function placeBid(Auction $auction, User $bidder, float $amount, bool $isAutoBid): Bid
    {
        $increment = (float) $auction->bid_increment;
        $standing = $this->standingCompetingBid($auction, $bidder->id);
        $previousWinner = $auction->bids()->where('is_winning', true)->first();

        if ($standing !== null && $standing->is_auto_bid && $amount <= (float) $standing->amount) {
            // The standing auto-bid defends itself: this new bid does not
            // win, and the price only rises to just beat it -- the
            // defender's true max stays hidden.
            $newPrice = min((float) $standing->amount, $amount + $increment);
            $winning = false;
        } elseif ($isAutoBid) {
            // This auto-bid wins (no competition, or it beat the standing
            // bid) -- but its own true max stays hidden too, ratcheted to
            // just beat whatever it's up against.
            $newPrice = $standing === null
                ? $auction->minimumBid()
                : min($amount, (float) $standing->amount + $increment);
            $winning = true;
        } else {
            // A plain manual bid that isn't beaten by a standing auto-bid:
            // wins outright, transparent price -- identical to this
            // platform's pre-existing bidding behavior.
            $newPrice = $amount;
            $winning = true;
        }

        if ($winning) {
            $auction->bids()->where('is_winning', true)->update(['is_winning' => false]);
        }

        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => $amount,
            'is_winning' => $winning,
            'is_auto_bid' => $isAutoBid,
        ]);

        $auction->update(['current_price' => $newPrice]);
        $auction->refresh();

        event(new BidPlaced($bid));

        if ($winning && $previousWinner && $previousWinner->bidder_id !== $bidder->id) {
            $previousWinner->bidder?->notify(new OutbidNotification($bid));
        }

        return $bid;
    }

    /**
     * The best competing bid from any OTHER bidder, using only each
     * bidder's latest row (a bidder's newest bid always supersedes their
     * own earlier stance) and comparing on amount (their max, whether
     * auto or manual) to find the current leader to beat.
     */
    private function standingCompetingBid(Auction $auction, int $excludingBidderId): ?Bid
    {
        return $auction->bids()
            ->where('bidder_id', '!=', $excludingBidderId)
            ->get()
            ->unique('bidder_id') // ->bids() is already latest()-ordered, so unique() keeps each bidder's newest row
            ->sortByDesc('amount')
            ->first();
    }
}
