<?php

namespace App\Livewire\Auctions;

use App\Events\AuctionSettled;
use App\Models\Auction;
use App\Models\ReserveNotMetProposal;
use App\Notifications\AuctionWonNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

class ReserveDecisionPanel extends Component
{
    public ReserveNotMetProposal $proposal;

    public function mount(ReserveNotMetProposal $proposal): void
    {
        $this->proposal = $proposal;
    }

    public function accept(): void
    {
        Gate::authorize('review', $this->proposal);

        $this->decide('accepted', function (Auction $auction, ReserveNotMetProposal $proposal) {
            DB::afterCommit(function () use ($auction, $proposal) {
                $proposal->bid->bidder->notify(new AuctionWonNotification($auction, $proposal->bid));
                // The sale is now settled just like a reserve-met one, so the
                // payment hand-off listener must hear about it too.
                event(new AuctionSettled($auction, $proposal->bid));
            });
        });
    }

    public function reject(): void
    {
        Gate::authorize('review', $this->proposal);

        $this->decide('rejected');
    }

    /**
     * A decision is only valid while the proposal is still pending, checked
     * against a locked, freshly read row -- not this component's hydrated
     * copy -- so a double click, a second tab or a stale page cannot decide
     * (or flip, or re-notify) a proposal twice.
     */
    private function decide(string $outcome, ?callable $afterDecision = null): void
    {
        DB::transaction(function () use ($outcome, $afterDecision) {
            $proposal = ReserveNotMetProposal::query()->lockForUpdate()->find($this->proposal->id);

            if ($proposal === null || $proposal->status !== 'pending') {
                return;
            }

            $auction = Auction::query()->lockForUpdate()->findOrFail($proposal->auction_id);

            $auction->update(['status' => 'ended']);
            $proposal->update(['status' => $outcome, 'decided_at' => now()]);

            if ($afterDecision !== null) {
                $afterDecision($auction, $proposal);
            }
        });

        // Show the real, current state (including a decision made elsewhere).
        $this->proposal = $this->proposal->fresh() ?? $this->proposal;
    }

    public function render(): View
    {
        return view('livewire.auctions.reserve-decision-panel');
    }
}
