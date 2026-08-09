<div style="padding:0.9rem 1.6rem;border-bottom:1px solid rgba(67,70,86,0.1);">
    @if ($proposal->status === 'pending')
        <div style="font-size:0.82rem;font-weight:600;color:#f4f4f5;font-family:'Syne',sans-serif;">
            "{{ $proposal->auction->title }}" ended below reserve.
        </div>
        <div style="font-size:0.7rem;color:#71717a;margin-top:0.15rem;">
            Top bid R{{ number_format((float) $proposal->bid->amount, 2) }}
            from {{ $proposal->bid->bidder->name }},
            reserve R{{ number_format((float) $proposal->reserve_price, 2) }}.
        </div>
        <div style="display:flex;gap:0.5rem;margin-top:0.6rem;">
            <button wire:click="accept"
                wire:confirm="Accept R{{ number_format((float) $proposal->bid->amount, 2) }} from {{ $proposal->bid->bidder->name }}?"
                style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.35);color:#4ade80;cursor:pointer;">
                Accept top bid
            </button>
            <button wire:click="reject" wire:confirm="End this auction unsold?"
                style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.3);color:#fb7185;cursor:pointer;">
                Reject
            </button>
        </div>
    @else
        <div style="font-size:0.78rem;color:#71717a;">
            {{ ucfirst($proposal->status) }} on {{ $proposal->decided_at?->format('M j, Y') }}.
        </div>
    @endif
</div>
