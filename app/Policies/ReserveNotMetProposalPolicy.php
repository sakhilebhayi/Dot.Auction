<?php

namespace App\Policies;

use App\Models\ReserveNotMetProposal;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReserveNotMetProposalPolicy
{
    use HandlesAuthorization;

    /**
     * Only the auction's own seller may decide a reserve-not-met proposal
     * -- mirrors AuctionPolicy::bid()'s existing seller-ownership check.
     */
    public function review(User $user, ReserveNotMetProposal $proposal): bool
    {
        return $user->id === $proposal->auction->seller_id;
    }
}
