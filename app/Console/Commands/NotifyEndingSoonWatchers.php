<?php

namespace App\Console\Commands;

use App\Models\Auction;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\AuctionEndingSoonNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class NotifyEndingSoonWatchers extends Command
{
    protected $signature = 'auction:notify-ending-soon';

    protected $description = 'Tell each watcher, once per auction, that a live auction they watch is about to close.';

    public function handle(): int
    {
        $now = now();
        $horizon = $now->copy()->addMinutes((int) config('auction.ending_soon_window_minutes', 60));

        $auctions = Auction::query()
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<=', $horizon)
            ->get();

        $sent = 0;

        foreach ($auctions as $auction) {
            $pending = Watchlist::withoutGlobalScopes()
                ->where('auction_id', $auction->id)
                ->where('user_id', '!=', $auction->seller_id)
                ->whereNull('ending_soon_notified_at')
                ->pluck('user_id');

            foreach ($pending as $userId) {
                // Claim the watch row first. The UPDATE only succeeds for
                // the one run that finds the marker still empty, so two
                // overlapping runs (or a retry) cannot both notify.
                $claimed = $this->watch($auction->id, $userId)
                    ->whereNull('ending_soon_notified_at')
                    ->update(['ending_soon_notified_at' => $now]);

                if ($claimed !== 1) {
                    continue;
                }

                $user = User::find($userId);

                if ($user === null) {
                    continue;
                }

                try {
                    $user->notify(new AuctionEndingSoonNotification($auction));
                    $sent++;
                } catch (\Throwable $e) {
                    // Give the claim back so the next run retries this one.
                    $this->watch($auction->id, $userId)->update(['ending_soon_notified_at' => null]);
                    $this->error("Could not notify user #{$userId} about auction #{$auction->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Sent {$sent} ending-soon notice(s).");

        return self::SUCCESS;
    }

    private function watch(int $auctionId, int $userId): Builder
    {
        return Watchlist::withoutGlobalScopes()
            ->where('auction_id', $auctionId)
            ->where('user_id', $userId);
    }
}
