<?php

namespace App\Livewire\Auctions;

use App\Models\Auction;
use App\Models\Bid;
use App\Services\Auctions\AuctionSnapshot;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Validate;
use Livewire\Component;

class BidPanel extends Component
{
    public Auction $auction;

    /**
     * Exactly what the browser sent, as text. A `float` property made a crafted request a 500: Livewire's property
     * assignment throws a TypeError for text ("abc") and its snapshot cannot hold the INF that "1e400" becomes. The
     * value is judged by BidPlacer, which accepts only a finite amount of at most 2 decimals.
     */
    #[Validate('required|numeric|min:0.01', message: [
        'required' => 'Enter the amount you want to bid.',
        'numeric' => 'Enter your bid as a number, for example 250 or 250.50.',
        'min' => 'Your bid must be more than zero.',
    ])]
    public string $bidAmount = '';

    /**
     * Client token for idempotency: a double-click or replayed request
     * carries the same token and is rejected by BidPlacer; it rotates after
     * every accepted bid.
     */
    public string $submissionToken = '';

    public bool $placing = false;

    public ?string $error = null;

    public ?string $success = null;

    /**
     * True once this component's user has taken Buy Now, so the panel can
     * say so instead of the generic "ended" line.
     */
    public bool $purchased = false;

    public function mount(Auction $auction): void
    {
        $this->auction = $auction;
        $this->bidAmount = Auction::fromCents($auction->minimumBidCents());
        $this->submissionToken = Str::uuid()->toString();
    }

    #[Computed]
    public function recentBids(): Collection
    {
        return $this->auction->bids()->with('bidder')->limit(10)->get();
    }

    #[Computed]
    public function timeRemaining(): string
    {
        if ($this->auction->isScheduled()) {
            return 'Not open yet';
        }

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

    /**
     * Two bugs fixed here (verified against vendor/livewire/livewire's own
     * source): "echo-public:" isn't a Livewire channel-type prefix at all
     * -- the only public-channel form is "echo:" (Livewire auto-splices in
     * "channel" as the type when it sees exactly that prefix); anything
     * else silently hits its "channel type not yet supported" fallback
     * and never subscribes. And "BidPlaced" needs to be
     * BidPlaced::broadcastAs()'s actual wire name ("bid.placed") with a
     * leading "." -- without the dot, Echo's default EventFormatter
     * prefixes it to "App.Events.bid.placed", which never matches. See
     * docs/DOT_REALTIME_STANDARD.md (Dot.Mines) §5. "{auction.id}" itself
     * was already correct -- Livewire's placeholder interpolation uses
     * data_get(), which supports dot-notation into a nested property.
     */
    #[On('echo:auction.{auction.id},.bid.placed')]
    public function refreshBids(array $data): void
    {
        $this->auction->refresh();
        $this->bidAmount = Auction::fromCents($this->auction->minimumBidCents());
        unset($this->recentBids);
    }

    /**
     * All rules (window, minimum, seller, duplicates) are enforced inside
     * BidPlacer under a row lock against freshly re-read data -- this
     * component's hydrated $auction is only ever used for display, never
     * to decide whether a bid is legal.
     */
    public function placeBid(): void
    {
        $this->error = null;
        $this->success = null;

        try {
            $this->validate();
        } catch (ValidationException $e) {
            // The same words in $error (what the panel prints today) and in the validation bag (for @error).
            $this->error = $e->validator->errors()->first('bidAmount') ?: 'Enter your bid as a number, for example 250 or 250.50.';
            $this->placing = false;

            throw $e;
        }

        $limit = config('auction.bid_rate_limit');
        $rateKey = 'bid:'.auth()->id();

        if (RateLimiter::tooManyAttempts($rateKey, $limit['max_attempts'])) {
            $this->error = 'Too many bids. Try again in '.RateLimiter::availableIn($rateKey).' seconds.';

            return;
        }
        RateLimiter::hit($rateKey, $limit['decay_seconds']);

        $this->placing = true;
        $closedAtBefore = $this->auction->fresh()?->ends_at;

        try {
            app(BidPlacer::class)->place(
                $this->auction,
                auth()->user(),
                $this->bidAmount,
                $this->submissionToken,
            );
        } catch (BidRejected $e) {
            $this->error = $e->getMessage();
            $this->placing = false;
            $this->syncFromDatabase();

            return;
        }

        $this->submissionToken = Str::uuid()->toString();
        $this->syncFromDatabase();
        $this->success = 'Bid placed successfully!';

        if ($closedAtBefore !== null && $this->auction->ends_at->greaterThan($closedAtBefore)) {
            $this->success .= ' Closing time extended to '.Auction::localTime($this->auction->ends_at, 'H:i:s', true).' because the bid came in the final minutes.';
        }

        $this->placing = false;
    }

    /**
     * Take the Buy Now option. Every rule is enforced by BuyNowPurchaser
     * under a row lock against fresh data; this only relays the outcome.
     */
    public function buyNow(): void
    {
        // A second click (or a replayed request) after the purchase went through is not an error: the sale stands,
        // so leave the success message in place instead of clearing it and then failing with "no longer active".
        if ($this->purchased) {
            return;
        }

        $this->error = null;
        $this->success = null;

        $limit = config('auction.bid_rate_limit');
        $rateKey = 'bid:'.auth()->id();

        if (RateLimiter::tooManyAttempts($rateKey, $limit['max_attempts'])) {
            $this->error = 'Too many attempts. Try again in '.RateLimiter::availableIn($rateKey).' seconds.';

            return;
        }
        RateLimiter::hit($rateKey, $limit['decay_seconds']);

        try {
            app(BuyNowPurchaser::class)->buy($this->auction, auth()->user());
        } catch (BidRejected $e) {
            $this->syncFromDatabase();

            // Another tab or request of this same buyer got there first: the auction is theirs, say so.
            if ($this->boughtByMeAlready()) {
                $this->markPurchased();

                return;
            }

            $this->error = $e->getMessage();

            return;
        }

        $this->syncFromDatabase();
        $this->markPurchased();
    }

    private function markPurchased(): void
    {
        $this->purchased = true;
        $this->error = null;
        $this->success = 'You bought this auction for '.Auction::money($this->auction->current_price).'. Payment is not open yet, so nothing is due now.';
    }

    /**
     * Whether the signed-in user already holds the winning bid at exactly the Buy Now price of an auction that has
     * ended (what a completed Buy Now looks like).
     */
    private function boughtByMeAlready(): bool
    {
        $auction = $this->auction;

        if ($auction->buy_now_price === null || $auction->status === 'active') {
            return false;
        }

        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->where('bidder_id', auth()->id())
            ->where('is_winning', true)
            ->get(['amount'])
            ->contains(fn ($bid) => Auction::toCents($bid->amount) === Auction::toCents($auction->buy_now_price));
    }

    /**
     * A small, cached picture of the auction for polling and for a client-side countdown (see AuctionSnapshot for
     * the contract). Renderless: it returns data to `$wire.snapshot()` and does not re-render the panel.
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function snapshot(): array
    {
        return app(AuctionSnapshot::class)->for($this->auction->getKey(), auth()->user());
    }

    private function syncFromDatabase(): void
    {
        $this->auction->refresh();
        $this->bidAmount = Auction::fromCents($this->auction->minimumBidCents());
        unset($this->recentBids);
    }

    public function render(): View
    {
        return view('livewire.auctions.bid-panel');
    }
}
