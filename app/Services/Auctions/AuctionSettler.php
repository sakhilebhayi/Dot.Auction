<?php

namespace App\Services\Auctions;

use App\Events\AuctionSettled;
use App\Events\ReserveNotMet;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Notifications\AuctionWonNotification;
use Illuminate\Support\Facades\DB;

/**
 * Settles one ended auction exactly once.
 *
 * The auction row is locked and its status/window re-read inside the
 * transaction, so two overlapping runs (cron overlap, manual run, retry)
 * cannot both settle it: the loser sees a non-active status and returns
 * false. Events and notifications go out only after commit.
 */
class AuctionSettler
{
    /**
     * @return bool true when this call settled the auction, false when it was
     *              already settled / not yet due (nothing was changed)
     */
    public function settle(Auction|int $auction): bool
    {
        $auctionId = $auction instanceof Auction ? $auction->getKey() : $auction;

        return DB::transaction(function () use ($auctionId) {
            $locked = Auction::query()->lockForUpdate()->find($auctionId);

            if ($locked === null || $locked->status !== 'active' || $locked->ends_at->isFuture()) {
                return false;
            }

            $topBid = Bid::query()
                ->where('auction_id', $locked->id)
                ->where('is_winning', true)
                ->lockForUpdate()
                ->first();

            if ($topBid === null) {
                $locked->update(['status' => 'ended']);
                DB::afterCommit(fn () => event(new AuctionSettled($locked, null)));

                return true;
            }

            if ($locked->reserveMet()) {
                $locked->update(['status' => 'ended']);
                DB::afterCommit(function () use ($locked, $topBid) {
                    $topBid->bidder->notify(new AuctionWonNotification($locked));
                    event(new AuctionSettled($locked, $topBid));
                });

                return true;
            }

            $locked->update(['status' => 'pending_review']);

            $proposal = ReserveNotMetProposal::create([
                'auction_id' => $locked->id,
                'bid_id' => $topBid->id,
                'reserve_price' => $locked->reserve_price,
                'status' => 'pending',
            ]);

            DB::afterCommit(fn () => event(new ReserveNotMet($proposal)));

            return true;
        });
    }
}
