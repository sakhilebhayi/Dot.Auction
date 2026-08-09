<?php

namespace App\Console\Commands;

use App\Events\AuctionSettled;
use App\Events\ReserveNotMet;
use App\Models\Auction;
use App\Models\ReserveNotMetProposal;
use App\Notifications\AuctionWonNotification;
use Illuminate\Console\Command;

class SettleEndedAuctions extends Command
{
    protected $signature = 'auction:settle-ended';

    protected $description = 'Settle active auctions whose bidding window has closed; reserve-not-met lots stop for seller review instead of auto-settling.';

    public function handle(): int
    {
        $dueAuctions = Auction::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($dueAuctions as $auction) {
            try {
                $this->settle($auction);
            } catch (\Throwable $e) {
                $this->error("Failed to settle auction #{$auction->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$dueAuctions->count()} ended auction(s).");

        return self::SUCCESS;
    }

    private function settle(Auction $auction): void
    {
        $topBid = $auction->winningBid;

        if ($topBid === null) {
            $auction->update(['status' => 'ended']);
            event(new AuctionSettled($auction, null));

            return;
        }

        if ($auction->reserveMet()) {
            $auction->update(['status' => 'ended']);
            $topBid->bidder->notify(new AuctionWonNotification($auction));
            event(new AuctionSettled($auction, $topBid));

            return;
        }

        $auction->update(['status' => 'pending_review']);

        $proposal = ReserveNotMetProposal::create([
            'auction_id' => $auction->id,
            'bid_id' => $topBid->id,
            'reserve_price' => $auction->reserve_price,
            'status' => 'pending',
        ]);

        event(new ReserveNotMet($proposal));
    }
}
