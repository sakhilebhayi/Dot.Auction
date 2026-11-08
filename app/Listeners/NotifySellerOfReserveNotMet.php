<?php

namespace App\Listeners;

use App\Events\ReserveNotMet;
use App\Models\ReserveNotMetProposal;
use App\Notifications\ReserveNotMetNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * F-09, the part that needs no new rule: when an auction closes below its reserve the seller is told, once.
 * (A deadline, what happens at it, and what the high bidder is told are owner decisions; see docs/R42_DECISIONS.md.)
 *
 * Once means once however often the event is delivered: the proposal row is claimed with a conditional UPDATE
 * (seller_notified_at IS NULL), and the claim is released if sending throws so a retry can try again. A proposal that
 * is no longer pending (the seller already decided) is not announced.
 */
class NotifySellerOfReserveNotMet implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ReserveNotMet $event): void
    {
        $claimed = ReserveNotMetProposal::query()
            ->whereKey($event->proposal->getKey())
            ->where('status', 'pending')
            ->whereNull('seller_notified_at')
            ->update(['seller_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $proposal = ReserveNotMetProposal::query()->with('auction.seller')->find($event->proposal->getKey());
        $seller = $proposal?->auction?->seller;

        if ($seller === null) {
            return;
        }

        try {
            $seller->notify(new ReserveNotMetNotification($proposal));
        } catch (\Throwable $e) {
            ReserveNotMetProposal::query()->whereKey($proposal->getKey())->update(['seller_notified_at' => null]);

            throw $e;
        }
    }
}
