<?php

namespace App\Policies;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Added (platform-loop pass, 2026-08-01) alongside the new buyer-facing
 * `/auctions` browse and `/auctions/{auction}` detail + bidding routes, so
 * those new routes are authorized explicitly rather than left wide open
 * with no Gate check at all. Draft auctions (not yet published by the
 * seller) are excluded from AuctionController@index/show for everyone but
 * the seller — see view().
 */
class AuctionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may view a published (non-draft) auction —
     * this is a public marketplace listing. A draft auction is only
     * visible to its own seller.
     */
    public function view(User $user, Auction $auction): bool
    {
        if ($auction->status === 'draft') {
            return $user->id === $auction->seller_id;
        }

        return true;
    }

    /**
     * Only the seller may see the raw reserve price. Buyer-facing UI must
     * use Auction::reserveMet() instead — never read reserve_price
     * directly for a non-seller. See wiki.md §5 on reserve confidentiality.
     */
    public function viewReserve(User $user, Auction $auction): bool
    {
        return $auction->reserveVisibleTo($user);
    }

    /**
     * A buyer may bid on any active auction they don't own themselves.
     * (Own-auction and active-status checks are enforced again inside
     * BidPanel::placeBid() — this Gate covers route-level access.)
     */
    public function bid(User $user, Auction $auction): bool
    {
        return $user->id !== $auction->seller_id;
    }

    /**
     * Any signed-in user may list an auction; they become its seller.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Editing is for the auction's own seller while it is still a draft or
     * live. Which fields may change (nothing but the description after the
     * first bid) is enforced under a row lock in ListingManager, because it
     * depends on bids that can land at any moment. Platform operators get
     * no override here: the operator flag opens the payment-operations pages only.
     */
    public function update(User $user, Auction $auction): bool
    {
        return $this->manage($user, $auction) && in_array($auction->status, ['draft', 'active'], true);
    }

    /**
     * Publish, cancel and delete: the seller only. Status rules are
     * reported to the seller as readable errors by ListingManager rather
     * than as a bare 403.
     */
    public function manage(User $user, Auction $auction): bool
    {
        return $user->id === $auction->seller_id;
    }
}
