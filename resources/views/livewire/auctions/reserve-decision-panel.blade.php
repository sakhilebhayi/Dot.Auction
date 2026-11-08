<div style="padding:0.9rem 1.6rem;border-bottom:1px solid var(--c-line);">
    @if ($proposal->status === 'pending')
        <div style="font-size:0.82rem;font-weight:600;color:var(--c-ink);font-family:'Syne',sans-serif;">
            "{{ $proposal->auction->title }}" ended below reserve.
        </div>
        <div style="font-size:0.7rem;color:var(--c-ink-3);margin-top:0.15rem;">
            Top bid {{ \App\Models\Auction::money($proposal->bid->amount) }}
            from {{ $proposal->bid->bidder->name }},
            reserve {{ \App\Models\Auction::money($proposal->reserve_price) }}.
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.6rem;">
            <button wire:click="accept" class="dot-pill-btn"
                wire:confirm="Accept {{ \App\Models\Auction::money($proposal->bid->amount) }} from {{ $proposal->bid->bidder->name }}?"
                style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:var(--c-live-bg);border:1px solid var(--c-live);color:var(--c-live);cursor:pointer;">
                Accept top bid
            </button>
            <button wire:click="reject" class="dot-pill-btn" wire:confirm="End this auction unsold?"
                style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:var(--c-urgent-bg);border:1px solid var(--c-urgent);color:var(--c-urgent);cursor:pointer;">
                Reject
            </button>
        </div>
    @else
        <div style="font-size:0.78rem;color:var(--c-ink-3);">
            {{ ucfirst($proposal->status) }} on @localtime($proposal->decided_at, 'M j, Y').
        </div>
    @endif
</div>
