<?php

namespace App\Livewire\Buyer;

use App\Models\Auction;
use App\Models\Watchlist;
use App\Support\Display;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Watch / Stop watching control on a lot card. It takes the lot's id, title and the viewer's current state from the
 * list that renders it, so a page of twelve cards costs no query per card; only pressing it touches the database.
 */
class WatchToggle extends Component
{
    #[Locked]
    public int $auctionId;

    /** Used only to name the button for assistive technology ("Watch Vintage Camera"). */
    #[Locked]
    public string $title = '';

    public bool $watching = false;

    public function mount(int $auctionId, string $title, bool $watching): void
    {
        $this->auctionId = $auctionId;
        $this->title = $title;
        $this->watching = $watching;
    }

    public function toggle(): void
    {
        $auction = Auction::query()->findOrFail($this->auctionId);

        // A draft is only its seller's; watching one's own lot is meaningless, so neither is offered.
        Gate::authorize('view', $auction);
        abort_if($auction->seller_id === auth()->id(), 403);

        $userId = auth()->id();
        $existing = Watchlist::query()->where('user_id', $userId)->where('auction_id', $auction->id);

        if (! $existing->exists() && ! Display::isWatchable($auction)) {
            return; // closed or cancelled: nothing left to watch for (removing a watch is always allowed)
        }

        if ($existing->exists()) {
            $existing->delete();
            $this->watching = false;
        } else {
            Watchlist::create(['user_id' => $userId, 'auction_id' => $auction->id]);
            $this->watching = true;
        }
    }

    public function render(): View
    {
        return view('livewire.buyer.watch-toggle');
    }
}
