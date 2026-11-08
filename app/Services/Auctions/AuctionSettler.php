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

            // Whatever the outcome below, the cached polling snapshot of this auction is out of date once it commits.
            DB::afterCommit(fn () => AuctionSnapshot::forget($locked->id));

            $topBid = Bid::query()
                ->where('auction_id', $locked->id)
                ->where('is_winning', true)
                ->lockForUpdate()
                ->first();

            if ($topBid === null) {
                $locked->forceFill(['status' => 'ended'])->save();
                DB::afterCommit(fn () => event(new AuctionSettled($locked, null)));

                return true;
            }

            if ($locked->reserveMet()) {
                $locked->forceFill(['status' => 'ended'])->save();
                DB::afterCommit(function () use ($locked, $topBid) {
                    // The hand-off that creates the settlement goes first: the auction is already ended and committed, and
                    // nothing else will ever re-announce it, so a notice that fails to send must not be able to stop it.
                    event(new AuctionSettled($locked, $topBid));

                    try {
                        $topBid->bidder->notify(new AuctionWonNotification($locked, $topBid));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });

                return true;
            }

            $locked->forceFill(['status' => 'pending_review'])->save();

            $proposal = ReserveNotMetProposal::forceCreate([
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
