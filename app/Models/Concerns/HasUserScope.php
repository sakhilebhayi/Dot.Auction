<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Auction ships Jetstream Teams (see app/Models/Team.php), but the
 * team scaffolding is not wired to any auction data — every tenant-owned
 * row in this domain (auctions, bids, watchlists) is keyed to an
 * individual user column (seller_id / bidder_id / user_id), not
 * team_id. So this trait is the user-scoped analog of Dot.Mines'
 * HasTeamFilters / Dot.Finance's HasUserScope, not a team-scoped one.
 *
 * It is applied ONLY to models that are genuinely private to the owning
 * user by domain design (see Watchlist). It is deliberately NOT applied
 * to Auction or Bid: auctions are public marketplace listings that any
 * user must be able to browse and bid on (AuctionPolicy::view() only
 * hides drafts from non-sellers), and bids must remain visible to the
 * auction's seller and to other bidders viewing the same auction's bid
 * history — scoping either to a single owning user's id would break
 * that legitimate cross-user visibility. A model using this trait
 * applies it via bootHasXxxScope() naming convention per model (see
 * Watchlist) so query against it is scoped to the authenticated user by
 * default, the same way a forgotten where('user_id', ...) call in a
 * future controller can no longer leak another user's rows.
 *
 * mass-assignment still sets the owning column explicitly at create
 * time (see WatchlistButton::toggle()); this scope only governs reads.
 */
trait HasUserScope
{
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
        });
    }
}
