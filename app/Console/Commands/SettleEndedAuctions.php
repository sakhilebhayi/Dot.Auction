<?php

namespace App\Console\Commands;

use App\Models\Auction;
use App\Services\Auctions\AuctionSettler;
use Illuminate\Console\Command;

class SettleEndedAuctions extends Command
{
    protected $signature = 'auction:settle-ended';

    protected $description = 'Settle active auctions whose bidding window has closed; reserve-not-met lots stop for seller review instead of auto-settling.';

    public function handle(AuctionSettler $settler): int
    {
        $dueIds = Auction::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->pluck('id');

        $settled = 0;

        foreach ($dueIds as $id) {
            try {
                // Idempotent: the settler re-checks status under a row lock
                // and returns false if another run got there first.
                if ($settler->settle($id)) {
                    $settled++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to settle auction #{$id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$settled} ended auction(s).");

        return self::SUCCESS;
    }
}
