<?php

namespace App\Livewire\Auctions;

use App\Models\Auction;
use App\Models\Watchlist;
use Livewire\Component;

class WatchlistButton extends Component
{
    public Auction $auction;

    public bool $watching = false;

    public function mount(Auction $auction): void
    {
        $this->auction  = $auction;
        $this->watching = Watchlist::where('user_id', auth()->id())
            ->where('auction_id', $auction->id)
            ->exists();
    }

    public function toggle(): void
    {
        $watchlist = Watchlist::where('user_id', auth()->id())
            ->where('auction_id', $this->auction->id)
            ->first();

        if ($watchlist) {
            $watchlist->delete();
            $this->watching = false;
        } else {
            Watchlist::create([
                'user_id'    => auth()->id(),
                'auction_id' => $this->auction->id,
            ]);
            $this->watching = true;
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.auctions.watchlist-button');
    }
}
