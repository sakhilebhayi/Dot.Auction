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
}
