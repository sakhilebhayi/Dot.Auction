<div class="dot-card" style="padding:1.5rem;">

    {{-- Current price + countdown --}}
    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:var(--c-ink-3);margin-bottom:0.3rem;">Current Bid</div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:var(--c-notice);line-height:1;">
                {{ \App\Models\Auction::money($auction->current_price) }}
            </div>
            <div style="font-size:11px;margin-top:0.4rem;font-weight:600;color:{{ $auction->reserveMet() ? 'var(--c-ok)' : 'var(--c-notice)' }};">
                <x-ds.icon :name="$auction->reserveMet() ? 'check-circle' : 'info'" :size="13" />
                {{ $auction->reserveMet() ? 'Reserve met' : 'Reserve not yet met' }}
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:var(--c-ink-3);margin-bottom:0.3rem;">Time Remaining</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.1rem;font-weight:600;color:{{ $auction->isActive() ? 'var(--c-ink)' : 'var(--c-ink-3)' }};">
                {{ $this->timeRemaining }}
            </div>
            @if($auction->snipe_extensions > 0)
            <div class="dot-help" style="margin-top:0.3rem;">
                Closing time extended {{ $auction->snipe_extensions }} {{ \Illuminate\Support\Str::plural('time', $auction->snipe_extensions) }} by late bids.
            </div>
            @endif
        </div>
    </div>

    @if($success)
    <div role="status" style="background:var(--c-live-bg);border:1px solid var(--c-live);color:var(--c-live);border-radius:8px;padding:0.65rem 0.9rem;font-size:12.5px;font-weight:600;margin-bottom:1rem;">
        {{ $success }}
    </div>
    @endif

    @if($error)
    <div role="alert" style="background:var(--c-urgent-bg);border:1px solid var(--c-urgent);color:var(--c-urgent);border-radius:8px;padding:0.65rem 0.9rem;font-size:12.5px;font-weight:600;margin-bottom:1rem;">
        {{ $error }}
    </div>
    @endif

    {{-- Bid form --}}
    @if($auction->isActive() && auth()->id() !== $auction->seller_id)
    <form wire:submit.prevent="placeBid" style="display:flex;gap:0.6rem;margin-bottom:1.5rem;">
        <div style="flex:1;min-width:0;position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--c-ink-3);font-size:13px;">{{ config('auction.currency_symbol') }}</span>
            <input
                type="number"
                step="0.01"
                aria-label="Your bid amount"
                wire:model="bidAmount"
                class="dot-input"
                style="padding-left:24px;"
                min="{{ $auction->minimumBid() }}"
            >
        </div>
        <button type="submit" class="dot-btn dot-btn-primary" wire:loading.attr="disabled" wire:target="placeBid">
            <span wire:loading.remove wire:target="placeBid">Place Bid</span>
            <span wire:loading wire:target="placeBid">Placing…</span>
        </button>
    </form>
    <div style="font-size:11px;color:var(--c-ink-3);margin-top:-1rem;margin-bottom:1.25rem;">
        Minimum bid: {{ \App\Models\Auction::money($auction->minimumBid()) }}
    </div>
    @if($auction->buyNowAvailable())
    <button type="button"
        wire:click="buyNow"
        wire:confirm="Buy this auction now for {{ \App\Models\Auction::money($auction->buy_now_price) }}? This ends the auction immediately and you become the winner."
        wire:loading.attr="disabled" wire:target="buyNow"
        class="dot-btn dot-btn-ghost dot-btn-block" style="margin-bottom:1.5rem;">
        Buy now for {{ \App\Models\Auction::money($auction->buy_now_price) }}
    </button>
    @endif
    @elseif(auth()->id() === $auction->seller_id)
    <div style="font-size:12px;color:var(--c-ink-3);margin-bottom:1.5rem;">You can't bid on your own auction.</div>
    @elseif($purchased)
    <div style="font-size:12px;color:var(--c-ink-2);margin-bottom:1.5rem;">Purchased through Buy Now.</div>
    @elseif($auction->isScheduled())
    <div style="font-size:12px;color:var(--c-ink-2);margin-bottom:1.5rem;">Bidding opens {{ $auction->starts_at->format('M j, Y \a\t H:i') }}.</div>
    @else
    <div style="font-size:12px;color:var(--c-ink-3);margin-bottom:1.5rem;">This auction has ended.</div>
    @endif

    {{-- Recent bids (bidder identity masked to a first name + initial —
         individual bidder identity is treated as private, matching the
         ecosystem's bid-privacy rules in wiki.md §5). --}}
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:var(--c-ink-3);margin-bottom:0.6rem;">
        Recent Bids
    </div>

    @if($this->recentBids->isEmpty())
        <div style="text-align:center;padding:1.5rem 0;">
            <x-ds.icon name="gavel" :size="26" />
            <div style="font-size:12px;color:var(--c-ink-3);">No bids yet. Be the first.</div>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:0.4rem;">
            @foreach($this->recentBids as $bid)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.75rem;border-radius:8px;background:{{ $bid->is_winning ? 'var(--c-notice-bg)' : 'var(--c-surface)' }};">
                <span style="font-size:12.5px;color:var(--c-ink-2);">
                    {{ \Illuminate\Support\Str::before($bid->bidder->name, ' ') }}
                    {{ \Illuminate\Support\Str::substr(\Illuminate\Support\Str::after($bid->bidder->name, ' '), 0, 1) }}.
                    @if($bid->is_winning)
                        <span class="dot-badge dot-badge-accent" style="margin-left:6px;">Leading</span>
                    @endif
                </span>
                <span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;color:var(--c-ink);">
                    {{ \App\Models\Auction::money($bid->amount) }}
                </span>
            </div>
            @endforeach
        </div>
    @endif

</div>
