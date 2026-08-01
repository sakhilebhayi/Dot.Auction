<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.75rem;">
        <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;letter-spacing:-0.02em;">
            Browse Auctions
        </div>
        <div style="font-size:0.78rem;color:#71717a;margin-top:0.25rem;">
            {{ $auctions->total() }} listing{{ $auctions->total() === 1 ? '' : 's' }} · search, filter, and bid in real time
        </div>
    </div>

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('auctions.index') }}" style="display:flex;gap:0.75rem;margin-bottom:1.75rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:220px;position:relative;">
            <span class="material-symbols-rounded" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:#52525b;">search</span>
            <input type="text" name="q" value="{{ $search }}" placeholder="Search auctions by title…" class="dot-input" style="padding-left:36px;">
        </div>
        <select name="category" class="dot-input" style="max-width:200px;" onchange="this.form.submit()">
            <option value="">All categories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected((string) $category === (string) $cat->id)>{{ $cat->name }} ({{ $cat->auctions_count }})</option>
            @endforeach
        </select>
        <select name="status" class="dot-input" style="max-width:160px;" onchange="this.form.submit()">
            <option value="">Any status</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="ended" @selected($status === 'ended')>Ended</option>
            <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
        </select>
        <button type="submit" class="dot-btn dot-btn-primary">Search</button>
    </form>

    {{-- Results grid --}}
    @if($auctions->count())
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.1rem;margin-bottom:2rem;">
        @foreach($auctions as $auction)
        <a href="{{ route('auctions.show', $auction) }}" class="dot-card" style="display:block;padding:1.25rem;text-decoration:none;transition:border-color .15s;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                @if($auction->category)
                <span style="font-size:0.68rem;padding:0.2rem 0.55rem;border-radius:9999px;background:rgba(99,102,241,0.12);border:1px solid rgba(99,102,241,0.2);color:#a5b4fc;font-weight:600;">
                    {{ $auction->category->name }}
                </span>
                @else
                <span></span>
                @endif

                @if($auction->status === 'active')
                <span style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.65rem;padding:0.2rem 0.55rem;border-radius:9999px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-weight:600;">
                    <span style="width:5px;height:5px;border-radius:9999px;background:#22c55e;"></span> Live
                </span>
                @elseif($auction->status === 'ended')
                <span style="font-size:0.65rem;padding:0.2rem 0.55rem;border-radius:9999px;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.25);color:#93c5fd;font-weight:600;">Ended</span>
                @else
                <span style="font-size:0.65rem;padding:0.2rem 0.55rem;border-radius:9999px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;font-weight:600;">Cancelled</span>
                @endif
            </div>

            <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:0.5rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                {{ $auction->title }}
            </div>

            <div style="display:flex;align-items:flex-end;justify-content:space-between;">
                <div>
                    <div style="font-size:10px;color:#52525b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.15rem;">Current bid</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:1.15rem;font-weight:700;color:#fcd34d;">R{{ number_format($auction->current_price, 2) }}</div>
                </div>
                @if($auction->isActive())
                <div style="text-align:right;font-size:11px;color:#71717a;">
                    ends {{ $auction->ends_at->diffForHumans() }}
                </div>
                @endif
            </div>
        </a>
        @endforeach
    </div>

    {{ $auctions->links() }}
    @else
    <div class="dot-card" style="padding:3rem 1.6rem;text-align:center;">
        <span class="material-symbols-rounded" style="font-size:40px;color:#374151;display:block;margin-bottom:0.75rem;">search_off</span>
        <div style="font-size:0.85rem;color:#555e7a;font-weight:500;">No auctions match your search.</div>
    </div>
    @endif

</div>

</x-app-layout>
