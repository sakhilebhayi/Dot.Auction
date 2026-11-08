<?php

namespace App\Livewire\Auctions;

use App\Models\Auction;
use App\Models\Bid;
use App\Services\Auctions\AuctionSnapshot;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use App\Services\Auctions\BuyNowPurchaser;
use App\Support\LotOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The bidding column of a lot page: price, clock, what the viewer should know, the bid form with a confirm step,
 * Buy Now, the settlement panel and the bid history.
 *
 * Live without Reverb: the browser calls snapshot() (renderless, cached 1 s, see AuctionSnapshot) on a timer
 * (resources/js/auction-live.js). Only when that picture differs from the one rendered does it call refreshState(),
 * which re-renders. With Reverb present the same refresh runs from the broadcast and the timer slows down.
 *
 * Every rule (window, minimum, seller, duplicate) is enforced by BidPlacer under a row lock against fresh data; this
 * component only relays the outcome in words and never decides whether a bid is legal.
 */
class BidPanel extends Component
{
    public Auction $auction;

    /**
     * Exactly what the browser sent, as text (a `float` property made a crafted request a 500: Livewire cannot hold the
     * INF that "1e400" becomes). It is cleaned and judged by cleanAmount() and BidPlacer::parseCents().
     */
    public string $bidAmount = '';

    /**
     * Client token for idempotency: a double-click or replayed request carries the same token and is rejected by
     * BidPlacer; it rotates after every accepted bid.
     */
    public string $submissionToken = '';

    public bool $placing = false;

    /** The message the panel prints. It belongs to the amount field when $errorField is set, otherwise it is a panel alert. */
    public ?string $error = null;

    public ?string $errorField = null;

    public ?string $success = null;

    /** True once this component's user has taken Buy Now, so the panel can say so instead of the generic "ended" line. */
    public bool $purchased = false;

    public bool $showAllBids = false;

    /** The amount the confirm dialog shows ("R1,300.00"). Set by review(), cleared when the bid is placed or abandoned. */
    public string $confirmAmount = '';

    /** What the screen reader hears when something changed on its own. Empty on first render and on a quiet refresh. */
    public string $announcement = '';

    /** "state|role|cents|count" of the last render: lets refreshState() tell what changed since the viewer last looked. */
    public string $seen = '';

    public function mount(Auction $auction): void
    {
        $this->auction = $auction;
        $this->bidAmount = Auction::fromCents($auction->minimumBidCents());
        $this->submissionToken = Str::uuid()->toString();
        $this->seen = $this->fingerprint();
    }

    /**
     * Reverb listener, only when Reverb is really the broadcaster: with no Echo on the page Livewire would log
     * "Laravel Echo cannot be found" for a listener that can never fire.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if (! $this->echoAvailable()) {
            return [];
        }

        return ['echo:auction.'.$this->auction->getKey().',.bid.placed' => 'refreshState'];
    }

    private function echoAvailable(): bool
    {
        return (bool) config('auction.realtime.enabled', true)
            && config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));
    }

    #[Computed]
    public function outcome(): LotOutcome
    {
        return LotOutcome::for($this->auction, auth()->user());
    }

    #[Computed]
    public function recentBids(): Collection
    {
        return $this->auction->bids()->with('bidder')->limit($this->showAllBids ? 100 : 10)->get();
    }

    #[Computed]
    public function totalBids(): int
    {
        return $this->outcome->bidCount;
    }

    public function toggleAllBids(): void
    {
        $this->showAllBids = ! $this->showAllBids;
        unset($this->recentBids);
    }

    /** The picture the browser compares with its polls: the same fields as snapshot(), in one string. */
    protected function liveSignature(): string
    {
        $s = $this->snapshotData();

        return implode('|', [$s['status'], $s['current_price_cents'] ?? '', $s['bid_count'] ?? '', $s['ends_at'] ?? '', ! empty($s['leading_bidder_is_me']) ? '1' : '0']);
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        return app(AuctionSnapshot::class)->for($this->auction->getKey(), auth()->user());
    }

    protected function pollSeconds(): int
    {
        return (int) ($this->snapshotData()['poll_seconds'] ?? 4);
    }

    /**
     * A small, cached picture of the auction for polling and for a client-side countdown (see AuctionSnapshot for the
     * contract). Renderless: it returns data to `$wire.snapshot()` and does not re-render the panel.
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function snapshot(): array
    {
        return $this->snapshotData();
    }

    /**
     * Re-read the lot and re-render. Called by the poller when the snapshot changed, by the Reverb listener, and every
     * poll while the result of a closed lot is still being recorded. `$typed` is what the viewer has typed in the amount
     * field (null when they have not touched it): it is kept unless the minimum has moved above it.
     */
    public function refreshState(mixed $typed = null): void
    {
        $this->syncFromDatabase(is_string($typed) || is_numeric($typed) ? trim((string) $typed) : null);
        $this->announceChanges();
    }

    /** The seller decided on a reserve proposal (ReserveDecisionPanel on this page): show the result. */
    #[On('reserve-decided')]
    public function refreshAfterDecision(): void
    {
        $this->refreshState();
    }

    /**
     * Step 1 of a bid: judge what was typed in words, and only then ask for confirmation. Nothing is placed here.
     */
    public function review(): void
    {
        $this->resetFeedback();
        $this->auction->refresh();
        unset($this->outcome);

        if ($blocked = $this->blockedReason()) {
            $this->error = $blocked;
            $this->syncFromDatabase();

            return;
        }

        $cents = $this->judgedAmount();

        if ($cents === null) {
            return;
        }

        $this->bidAmount = Auction::fromCents($cents);
        $this->confirmAmount = Auction::money($cents / 100);
        $this->dispatch('ds-dialog-open', id: $this->confirmId());
    }

    /** The one-tap path for someone who was outbid: put the lowest valid bid in the field and go to the confirm step. */
    public function bidMinimum(): void
    {
        $this->auction->refresh();
        $this->bidAmount = Auction::fromCents($this->auction->minimumBidCents());
        $this->review();
    }

    /** Close the confirm dialog without bidding (Esc and "Change amount" close it in the browser; this clears the server copy). */
    public function cancelReview(): void
    {
        $this->confirmAmount = '';
    }

    /**
     * Step 2: place the bid. The browser reaches this from the confirm dialog; tests and any other caller may reach it
     * directly, so it judges the amount itself.
     */
    public function placeBid(): void
    {
        $this->closeDialog($this->confirmId());
        $this->auction->refresh();
        unset($this->outcome);

        // A second press of the same confirm button (a double click, a replayed request) arrives after the first bid went
        // through and the viewer already leads: it is not a new bid and not an error, so leave the success message standing.
        if ($this->success !== null && $this->outcome->viewerLeads) {
            return;
        }

        $this->resetFeedback();

        if ($blocked = $this->blockedReason()) {
            $this->error = $blocked;
            $this->syncFromDatabase();

            return;
        }

        $wasConfirming = $this->confirmAmount !== '';
        $cents = $this->judgedAmount();

        if ($cents === null) {
            // The viewer confirmed an amount that was valid a moment ago: the price moved while they were deciding.
            if ($wasConfirming && str_starts_with((string) $this->error, 'Your bid is too low')) {
                $minimum = $this->auction->minimumBidCents();
                $this->bidAmount = Auction::fromCents($minimum);
                $this->fieldError('Someone bid first. The next valid bid is now '.Auction::money($minimum / 100).'.');
                $this->confirmAmount = '';
            }

            return;
        }

        $limit = config('auction.bid_rate_limit');
        $rateKey = 'bid:'.auth()->id();

        if (RateLimiter::tooManyAttempts($rateKey, $limit['max_attempts'])) {
            $this->error = 'Too many bids. Try again in '.RateLimiter::availableIn($rateKey).' seconds.';

            return;
        }
        RateLimiter::hit($rateKey, $limit['decay_seconds']);

        $this->placing = true;
        $closedAtBefore = $this->auction->ends_at;
        $minimumBefore = $this->auction->minimumBidCents();

        try {
            app(BidPlacer::class)->place($this->auction, auth()->user(), Auction::fromCents($cents), $this->submissionToken);
        } catch (BidRejected $e) {
            $this->placing = false;
            $this->syncFromDatabase();
            $this->explainRejection($e, $cents, $minimumBefore);

            return;
        }

        $this->placing = false;
        $this->confirmAmount = '';
        $this->submissionToken = Str::uuid()->toString();
        $this->syncFromDatabase();

        $this->success = 'Your bid of '.Auction::money($cents / 100).' is in.';

        if ($this->auction->ends_at->greaterThan($closedAtBefore)) {
            $this->success .= ' Closing time extended to '.Auction::localTime($this->auction->ends_at, 'H:i:s', true).' because the bid came in the final minutes.';
        }

        $this->announcement = $this->success.' You are leading.';
        $this->seen = $this->fingerprint();
    }

    /**
     * Take the Buy Now option. Every rule is enforced by BuyNowPurchaser under a row lock against fresh data; this only
     * relays the outcome. A second click (or a replayed request) after the purchase went through is not an error.
     */
    public function buyNow(): void
    {
        $this->closeDialog($this->buyNowId());

        if ($this->purchased) {
            return;
        }

        $this->resetFeedback();

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

            // Another tab or request of this same buyer got there first: the lot is theirs, say so.
            if ($this->boughtByMeAlready()) {
                $this->markPurchased();

                return;
            }

            $this->error = $this->friendlyBuyNow($e->getMessage());

            return;
        }

        $this->syncFromDatabase();
        $this->markPurchased();
    }

    private function markPurchased(): void
    {
        $this->purchased = true;
        $this->error = null;
        $this->errorField = null;
        $this->success = 'You bought this lot for '.Auction::money($this->auction->current_price).'.';
        $this->announcement = $this->success.' Payment and the next steps are on this page.';
        $this->seen = $this->fingerprint();
    }

    private function friendlyBuyNow(string $message): string
    {
        return match (true) {
            str_contains($message, 'no longer active') => 'This lot has closed, so Buy Now is no longer possible. Nothing was bought.',
            str_contains($message, 'no longer available') => 'Bidding has reached the Buy Now price, so Buy Now is gone. Nothing was bought.',
            default => $message,
        };
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

    /** Why the form cannot be used right now (the lot moved on in another tab), or null. */
    private function blockedReason(): ?string
    {
        $outcome = $this->outcome;

        if ($outcome->isSeller) {
            return 'You cannot bid on your own auction.';
        }

        if (! $outcome->isLive()) {
            return $outcome->state === 'scheduled'
                ? 'This lot is not open for bids yet.'
                : 'This lot has closed. Your bid was not placed.';
        }

        if ($outcome->viewerLeads) {
            return 'You are already leading. A higher bid from you would only raise your own price.';
        }

        return null;
    }

    /**
     * Turn what was typed into whole cents, or print why not under the field and return null. The same checks, in the
     * same order, as BidPlacer, so the message appears before anything is sent for confirmation.
     */
    private function judgedAmount(): ?int
    {
        $clean = $this->cleanAmount($this->bidAmount);
        $this->bidAmount = $clean;

        try {
            $this->validate(['bidAmount' => 'required|numeric|min:0.01'], [
                'bidAmount.required' => 'Enter the amount you want to bid.',
                'bidAmount.numeric' => 'Enter your bid as a number, for example 250 or 250.50.',
                'bidAmount.min' => 'Your bid must be more than zero.',
            ]);
        } catch (ValidationException $e) {
            return $this->fieldError($e->validator->errors()->first('bidAmount') ?: 'Enter your bid as a number, for example 250 or 250.50.');
        }

        try {
            $cents = app(BidPlacer::class)->parseCents($clean);
        } catch (BidRejected $e) {
            return $this->fieldError($e->getMessage() === 'Enter a valid bid amount.'
                ? 'Enter your bid as a number, for example 250 or 250.50.'
                : $e->getMessage());
        }

        $minimum = $this->auction->minimumBidCents();

        if ($cents < $minimum) {
            return $this->fieldError('Your bid is too low. The next valid bid is '.Auction::money($minimum / 100).'.');
        }

        return $cents;
    }

    /** Allow "R 1 300", "R1,300.50" and "1 300.50": a currency symbol, spaces and thousands commas are dropped. Nothing else is guessed. */
    private function cleanAmount(string $typed): string
    {
        $t = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $typed));
        $symbol = (string) config('auction.currency_symbol', 'R');

        if ($symbol !== '' && str_starts_with(strtoupper($t), strtoupper($symbol))) {
            $t = substr($t, strlen($symbol));
        }

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $t)) {
            $t = str_replace(',', '', $t);
        }

        return $t;
    }

    private function fieldError(string $message): null
    {
        $this->error = $message;
        $this->errorField = 'bidAmount';
        $this->addError('bidAmount', $message);
        $this->announcement = $message;
        $this->dispatch('lot-bid-focus');

        return null;
    }

    /** BidPlacer refused after our own checks (the lot moved while the viewer was deciding): say what changed. */
    private function explainRejection(BidRejected $e, int $cents, int $minimumBefore): void
    {
        $message = $e->getMessage();
        $minimumNow = $this->auction->minimumBidCents();

        if (str_starts_with($message, 'Minimum bid is')) {
            $this->fieldError($minimumNow > $minimumBefore
                ? 'Someone bid first. The next valid bid is now '.Auction::money($minimumNow / 100).'.'
                : 'Your bid is too low. The next valid bid is '.Auction::money($minimumNow / 100).'.');

            return;
        }

        if (str_starts_with($message, 'Duplicate bid')) {
            $this->fieldError('You already bid '.Auction::money($cents / 100).' on this lot. Enter a higher amount.');

            return;
        }

        if ($message === 'This bid was already submitted.') {
            // The first press went through; a second request of the same form is not a new bid. Say so if it is now the lead.
            unset($this->outcome);

            if ($this->outcome->viewerLeads) {
                $this->success = 'Your bid of '.Auction::money($cents / 100).' is in.';
                $this->announcement = $this->success.' You are leading.';
            } else {
                $this->error = 'That bid was already sent. Check the bid history before bidding again.';
            }

            return;
        }

        if ($message === 'This auction is no longer active.') {
            $this->error = 'This lot has closed. Your bid was not placed.';

            return;
        }

        if (str_starts_with($message, 'Bids above') || str_starts_with($message, 'Bid amounts')) {
            $this->fieldError($message);

            return;
        }

        $this->error = $message;
    }

    private function resetFeedback(): void
    {
        $this->error = null;
        $this->errorField = null;
        $this->success = null;
        $this->announcement = '';
        $this->resetErrorBag();
    }

    private function confirmId(): string
    {
        return 'confirm-bid-'.$this->auction->getKey();
    }

    private function buyNowId(): string
    {
        return 'confirm-buy-'.$this->auction->getKey();
    }

    private function closeDialog(string $id): void
    {
        $this->dispatch('ds-dialog-close', id: $id);
    }

    private function fingerprint(): string
    {
        $o = $this->outcome;

        return implode('|', [$o->state, $o->role, $o->currentCents, $o->bidCount]);
    }

    /**
     * Say, once, what changed since the viewer last looked, for a screen reader (aria-live polite). A quiet refresh
     * where nothing changed says nothing.
     */
    private function announceChanges(): void
    {
        $before = explode('|', $this->seen) + array_fill(0, 4, '');
        $now = $this->fingerprint();
        $this->announcement = '';

        if ($now === $this->seen) {
            return;
        }

        $o = $this->outcome;
        $this->seen = $now;

        if ($o->state !== $before[0] && ! $o->isLive()) {
            $this->announcement = 'Bidding has closed. '.($o->box['headline'] ?? '').'.';
            $this->success = null;

            return;
        }

        if ($o->role === 'outbid' && $before[1] === 'leader') {
            $this->announcement = 'You were outbid. The price is now '.Auction::money($o->currentCents / 100).'. The next valid bid is '.Auction::money($o->minimumCents / 100).'.';
            $this->success = null;

            return;
        }

        if ((string) $o->currentCents !== $before[2]) {
            $this->announcement = 'New bid: '.Auction::money($o->currentCents / 100).'.'.($o->viewerLeads ? ' You are leading.' : '');
        }
    }

    /** Re-read the lot; keep what the viewer typed unless the minimum has risen above it. */
    private function syncFromDatabase(?string $typed = null): void
    {
        $this->auction->refresh();
        unset($this->outcome, $this->recentBids, $this->totalBids);

        $minimum = $this->auction->minimumBidCents();
        $candidate = $typed !== null && $typed !== '' ? $typed : $this->bidAmount;
        $clean = $this->cleanAmount($candidate);

        $this->bidAmount = is_numeric($clean) && Auction::toCents($clean) >= $minimum && (float) $clean > 0
            ? $clean
            : Auction::fromCents($minimum);
    }

    public function render(): View
    {
        return view('livewire.auctions.bid-panel', [
            'o' => $this->outcome,
            'sig' => $this->liveSignature(),
            'poll' => $this->pollSeconds(),
            'realtime' => $this->echoAvailable(),
            'confirmId' => $this->confirmId(),
            'buyNowId' => $this->buyNowId(),
        ]);
    }
}
