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
        // `watchlists` has a composite primary key (user_id, auction_id) and no
        // surrogate `id` column, so Eloquent's model-instance delete() (which
        // keys off getKey()/`id`) silently deletes zero rows. Delete via the
        // query builder, scoped to the same two columns, instead.
        $exists = Watchlist::where('user_id', auth()->id())
            ->where('auction_id', $this->auction->id)
            ->exists();

        if ($exists) {
            Watchlist::where('user_id', auth()->id())
                ->where('auction_id', $this->auction->id)
                ->delete();
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
