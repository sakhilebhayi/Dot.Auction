<?php

namespace App\Livewire\Auctions;

use App\Models\ReserveNotMetProposal;
use App\Notifications\AuctionWonNotification;
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

        $bid = $this->proposal->bid;

        $this->proposal->auction->update(['status' => 'ended']);
        $bid->bidder->notify(new AuctionWonNotification($this->proposal->auction));
        $this->proposal->update(['status' => 'accepted', 'decided_at' => now()]);
    }

    public function reject(): void
    {
        Gate::authorize('review', $this->proposal);

        $this->proposal->auction->update(['status' => 'ended']);
        $this->proposal->update(['status' => 'rejected', 'decided_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.auctions.reserve-decision-panel');
    }
}
