<?php

namespace App\Console\Commands;

use App\Models\Auction;
use App\Services\Auctions\AuctionSettler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RepairUnsettledAuctions extends Command
{
    protected $signature = 'auction:repair-unsettled';

    protected $description = 'Create the missing settlement for ended auctions that have a winner but no settlement (a lost hand-off). Logs at error level for every repair.';

    public function handle(AuctionSettler $settler): int
    {
        $lookback = max(1, (int) config('auction.settlement_sweep.lookback_days', 7));
        $grace = max(0, (int) config('auction.settlement_sweep.grace_minutes', 15));

        $ids = Auction::query()
            ->where('status', 'ended')
            ->where('ends_at', '<=', now()->subMinutes($grace))
            ->where('ends_at', '>=', now()->subDays($lookback))
            ->whereExists(fn ($q) => $q->from('bids')->whereColumn('bids.auction_id', 'auctions.id')->where('bids.is_winning', true))
            ->whereNotExists(fn ($q) => $q->from('auction_settlements')->whereColumn('auction_settlements.auction_id', 'auctions.id'))
            ->whereNotExists(fn ($q) => $q->from('reserve_not_met_proposals')
                ->whereColumn('reserve_not_met_proposals.auction_id', 'auctions.id')
                ->where('reserve_not_met_proposals.status', 'rejected'))
            ->orderBy('id')
            ->pluck('id');

        $repaired = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                if ($settler->repairMissingSettlement($id)) {
                    $repaired++;
                    // Error level on purpose: a repair means the normal hand-off failed, and someone should look at why.
                    Log::error('Repaired an ended auction that had a winner but no settlement.', ['auction_id' => $id]);
                }
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->error("Could not repair auction #{$id}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$ids->count()} auction(s); repaired {$repaired}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
