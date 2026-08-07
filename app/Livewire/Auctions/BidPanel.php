<?php

namespace App\Livewire\Auctions;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Notifications\OutbidNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class BidPanel extends Component
{
    public Auction $auction;

    #[Validate('required|numeric')]
    public float $bidAmount = 0;

    public bool $placing = false;

    public ?string $error = null;

    public ?string $success = null;

    public function mount(Auction $auction): void
    {
        $this->auction = $auction;
        $this->bidAmount = $auction->minimumBid();
    }

    #[Computed]
    public function recentBids(): Collection
    {
        return $this->auction->bids()->with('bidder')->limit(10)->get();
    }

    #[Computed]
    public function timeRemaining(): string
    {
        if (! $this->auction->isActive()) {
            return 'Ended';
        }

        $diff = now()->diff($this->auction->ends_at);

        if ($diff->days > 0) {
            return $diff->days.'d '.$diff->h.'h';
        }
        if ($diff->h > 0) {
            return $diff->h.'h '.$diff->i.'m';
        }

        return $diff->i.'m '.$diff->s.'s';
    }

    #[On('echo-public:auction.{auction.id},BidPlaced')]
    public function refreshBids(array $data): void
    {
        $this->auction->refresh();
        $this->bidAmount = $this->auction->minimumBid();
        unset($this->recentBids);
    }

    public function placeBid(): void
    {
        $this->validate();
        $this->error = null;
        $this->success = null;

        if (! $this->auction->isActive()) {
            $this->error = 'This auction is no longer active.';

            return;
        }

        if (auth()->id() === $this->auction->seller_id) {
            $this->error = 'You cannot bid on your own auction.';

            return;
        }

        $minimum = $this->auction->minimumBid();
        if ($this->bidAmount < $minimum) {
            $this->error = 'Minimum bid is R'.number_format($minimum, 2);

            return;
        }

        $this->placing = true;

        // Capture the previous leader before we overwrite is_winning, so we
        // can notify them that they've been outbid.
        $previousWinner = $this->auction->bids()->where('is_winning', true)->first();

        // Mark previous winning bid as not winning
        $this->auction->bids()->where('is_winning', true)->update(['is_winning' => false]);

        $bid = Bid::create([
            'auction_id' => $this->auction->id,
            'bidder_id' => auth()->id(),
            'amount' => $this->bidAmount,
            'is_winning' => true,
        ]);

        $this->auction->update(['current_price' => $this->bidAmount]);
        $this->auction->refresh();
        $this->bidAmount = $this->auction->minimumBid();

        event(new BidPlaced($bid));

        if ($previousWinner && $previousWinner->bidder_id !== $bid->bidder_id) {
            $previousWinner->bidder?->notify(new OutbidNotification($bid));
        }

        $this->success = 'Bid placed successfully!';
        $this->placing = false;
        unset($this->recentBids);
    }

    public function render(): View
    {
        return view('livewire.auctions.bid-panel');
    }
}
