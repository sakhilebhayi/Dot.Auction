<?php

namespace App\Policies;

use App\Models\AuctionSettlement;
use App\Models\User;

/**
 * A settlement is a private matter between its winner and its seller. Anyone else is treated as if it
 * does not exist (the controllers answer 404 before asking for anything stronger). The winner alone
 * may pay; the seller alone may cancel.
 */
class AuctionSettlementPolicy
{
    public function view(User $user, AuctionSettlement $settlement): bool
    {
        return $settlement->isWinner($user) || $settlement->isSeller($user);
    }

    public function pay(User $user, AuctionSettlement $settlement): bool
    {
        return $settlement->isWinner($user);
    }

    public function cancel(User $user, AuctionSettlement $settlement): bool
    {
        return $settlement->isSeller($user);
    }
}
