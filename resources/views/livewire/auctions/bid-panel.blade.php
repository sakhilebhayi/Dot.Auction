<div class="dot-card" style="padding:1.5rem;">

    {{-- Current price + countdown --}}
    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#71717a;margin-bottom:0.3rem;">Current Bid</div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#fcd34d;line-height:1;">
                R{{ number_format($auction->current_price, 2) }}
            </div>
            <div style="font-size:11px;margin-top:0.4rem;font-weight:600;color:{{ $auction->reserveMet() ? '#4ade80' : '#f59e0b' }};">
                <span class="material-symbols-rounded" style="font-size:13px;vertical-align:-2px;">{{ $auction->reserveMet() ? 'verified' : 'info' }}</span>
                {{ $auction->reserveMet() ? 'Reserve met' : 'Reserve not yet met' }}
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#71717a;margin-bottom:0.3rem;">Time Remaining</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.1rem;font-weight:600;color:{{ $auction->isActive() ? '#f4f4f5' : '#71717a' }};">
                {{ $this->timeRemaining }}
            </div>
        </div>
    </div>

    @if($success)
    <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;border-radius:8px;padding:0.65rem 0.9rem;font-size:12.5px;font-weight:600;margin-bottom:1rem;">
        {{ $success }}
    </div>
    @endif

    @if($error)
    <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;border-radius:8px;padding:0.65rem 0.9rem;font-size:12.5px;font-weight:600;margin-bottom:1rem;">
        {{ $error }}
    </div>
    @endif

    {{-- Bid form --}}
    @if($auction->isActive() && auth()->id() !== $auction->seller_id)
    <form wire:submit.prevent="placeBid" style="display:flex;gap:0.6rem;margin-bottom:1.5rem;">
        <div style="flex:1;position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#71717a;font-size:13px;">R</span>
            <input
                type="number"
                step="0.01"
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
    <div style="font-size:11px;color:#52525b;margin-top:-1rem;margin-bottom:1.25rem;">
        Minimum bid: R{{ number_format($auction->minimumBid(), 2) }}
    </div>
    @elseif(auth()->id() === $auction->seller_id)
    <div style="font-size:12px;color:#52525b;margin-bottom:1.5rem;">You can't bid on your own auction.</div>
    @else
    <div style="font-size:12px;color:#52525b;margin-bottom:1.5rem;">This auction has ended.</div>
    @endif

    {{-- Recent bids (bidder identity masked to a first name + initial —
         individual bidder identity is treated as private, matching the
         ecosystem's bid-privacy rules in wiki.md §5). --}}
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#71717a;margin-bottom:0.6rem;">
        Recent Bids
    </div>

    @if($this->recentBids->isEmpty())
        <div style="text-align:center;padding:1.5rem 0;">
            <span class="material-symbols-rounded" style="font-size:26px;color:#374151;display:block;margin-bottom:0.4rem;">gavel</span>
            <div style="font-size:12px;color:#52525b;">No bids yet. Be the first.</div>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:0.4rem;">
            @foreach($this->recentBids as $bid)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.75rem;border-radius:8px;background:{{ $bid->is_winning ? 'rgba(217,119,6,0.08)' : 'rgba(255,255,255,0.02)' }};">
                <span style="font-size:12.5px;color:#a1a1aa;">
                    {{ \Illuminate\Support\Str::before($bid->bidder->name, ' ') }}
                    {{ \Illuminate\Support\Str::substr(\Illuminate\Support\Str::after($bid->bidder->name, ' '), 0, 1) }}.
                    @if($bid->is_winning)
                        <span class="dot-badge dot-badge-accent" style="margin-left:6px;">Leading</span>
                    @endif
                </span>
                <span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;color:#f4f4f5;">
                    R{{ number_format($bid->amount, 2) }}
                </span>
            </div>
            @endforeach
        </div>
    @endif

</div>
