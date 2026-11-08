<?php

namespace App\Livewire\Auctions;

use App\Models\Auction;
use App\Models\Watchlist;
use Illuminate\View\View;
use Livewire\Component;

class WatchlistButton extends Component
{
    public Auction $auction;

    public bool $watching = false;

    /** Said to screen readers after a change. */
    public string $message = '';

    public function mount(Auction $auction): void
    {
        $this->auction = $auction;
        // HasUserScope already restricts this query to the authenticated
        // user's own rows, so no explicit where('user_id', ...) is needed.
        $this->watching = Watchlist::where('auction_id', $auction->id)->exists();
    }

    public function toggle(): void
    {
        // A seller does not watch their own lot (the page does not offer the button; this closes the request path too).
        if (auth()->id() === $this->auction->seller_id) {
            return;
        }

        // `watchlists` has a composite primary key (user_id, auction_id) and no
        // surrogate `id` column, so Eloquent's model-instance delete() (which
        // keys off getKey()/`id`) silently deletes zero rows. Delete via the
        // query builder, scoped to the same two columns, instead.
        // HasUserScope already restricts these queries to the authenticated
        // user's own rows.
        $exists = Watchlist::where('auction_id', $this->auction->id)->exists();

        if ($exists) {
            Watchlist::where('auction_id', $this->auction->id)->delete();
            $this->watching = false;
            $this->message = 'Removed from your watchlist.';
        } else {
            Watchlist::create([
                'user_id' => auth()->id(),
                'auction_id' => $this->auction->id,
            ]);
            $this->watching = true;
            $this->message = 'Added to your watchlist.';
        }
    }

    public function render(): View
    {
        return view('livewire.auctions.watchlist-button');
    }
}
