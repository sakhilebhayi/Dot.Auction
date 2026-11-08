<x-app-layout title="Browse lots" :title-visible="false">

<div class="dot-page">

    <div style="margin-bottom:1.75rem;">
        <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--c-ink);letter-spacing:-0.02em;">
            Browse Auctions
        </div>
        <div style="font-size:0.78rem;color:var(--c-ink-3);margin-top:0.25rem;">
            {{ $auctions->total() }} listing{{ $auctions->total() === 1 ? '' : 's' }} · search, filter, and bid in real time
        </div>
    </div>

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('auctions.index') }}" class="dot-filter-form">
        <div style="flex:1;min-width:220px;position:relative;">
            <x-ds.icon name="search" :size="17" />
            <input type="text" name="q" value="{{ $search }}" placeholder="Search auctions by title…" aria-label="Search auctions by title" class="dot-input" style="padding-left:36px;">
        </div>
        <select name="category" aria-label="Category" class="dot-input" style="max-width:200px;" data-autosubmit>
            <option value="">All categories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected((string) $category === (string) $cat->id)>{{ $cat->name }} ({{ $cat->auctions_count }})</option>
            @endforeach
        </select>
        <select name="status" aria-label="Status" class="dot-input" style="max-width:160px;" data-autosubmit>
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
            @if($auction->images->isNotEmpty())
            <img class="dot-cover" src="{{ $auction->images->first()->url() }}" alt="" loading="lazy">
            @endif
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                @if($auction->category)
                <span style="font-size:0.68rem;padding:0.2rem 0.55rem;border-radius:9999px;background:var(--c-scheduled-bg);border:1px solid var(--c-scheduled);color:var(--c-scheduled);font-weight:600;">
                    {{ $auction->category->name }}
                </span>
                @else
                <span></span>
                @endif

                @if($auction->isScheduled())
                <span class="dot-status dot-status-scheduled">Opens {{ $auction->starts_at->format('M j') }}</span>
                @elseif($auction->status === 'active')
                <span style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.65rem;padding:0.2rem 0.55rem;border-radius:9999px;background:var(--c-live-bg);border:1px solid var(--c-live);color:var(--c-live);font-weight:600;">
                    <span style="width:5px;height:5px;border-radius:9999px;background:var(--c-live-bg);"></span> Live
                </span>
                @elseif($auction->status === 'ended')
                <span style="font-size:0.65rem;padding:0.2rem 0.55rem;border-radius:9999px;background:var(--c-scheduled-bg);border:1px solid var(--c-scheduled);color:var(--c-scheduled);font-weight:600;">Ended</span>
                @else
                <span style="font-size:0.65rem;padding:0.2rem 0.55rem;border-radius:9999px;background:var(--c-urgent-bg);border:1px solid var(--c-urgent);color:var(--c-urgent);font-weight:600;">Cancelled</span>
                @endif
            </div>

            <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--c-ink);margin-bottom:0.5rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                {{ $auction->title }}
            </div>

            <div style="display:flex;align-items:flex-end;justify-content:space-between;">
                <div>
                    <div style="font-size:10px;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.15rem;">Current bid</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:1.15rem;font-weight:700;color:var(--c-notice);">{{ \App\Models\Auction::money($auction->current_price) }}</div>
                </div>
                @if($auction->isActive())
                <div style="text-align:right;font-size:11px;color:var(--c-ink-3);">
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
        <x-ds.icon name="search" :size="40" />
        <div style="font-size:0.85rem;color:var(--c-ink-3);font-weight:500;">No auctions match your search.</div>
    </div>
    @endif

</div>

</x-app-layout>
