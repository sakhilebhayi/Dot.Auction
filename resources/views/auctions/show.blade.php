<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:1100px;">

    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('auctions.index') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to browse
        </a>
    </div>

    <div class="dot-two-col" style="display:grid;grid-template-columns:1.4fr 1fr;gap:1.5rem;align-items:start;">

        {{-- Left column: auction details --}}
        <div>
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:0.75rem;flex-wrap:wrap;">
                <div>
                    <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.35rem;letter-spacing:-0.01em;">
                        {{ $auction->title }}
                    </h1>
                    <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
                        @if($auction->category)
                        <span style="font-size:0.7rem;padding:0.2rem 0.6rem;border-radius:9999px;background:rgba(99,102,241,0.12);border:1px solid rgba(99,102,241,0.2);color:#a5b4fc;font-weight:600;">
                            {{ $auction->category->name }}
                        </span>
                        @endif
                        <span style="font-size:0.75rem;color:#71717a;">Seller: {{ $auction->seller->name ?? 'Unknown' }}</span>
                    </div>
                </div>

                @auth
                <livewire:auctions.watchlist-button :auction="$auction" :key="'watch-'.$auction->id" />
                @endauth
            </div>

            @if($auction->images->isNotEmpty())
            @php($photoCount = $auction->images->count())
            <div style="margin-bottom:1.25rem;">
                <img class="dot-gallery-main" src="{{ $auction->images->first()->url() }}"
                     width="{{ $auction->images->first()->width }}" height="{{ $auction->images->first()->height }}"
                     alt="{{ $auction->title }}, photo 1 of {{ $photoCount }}">
                @if($photoCount > 1)
                <ul class="dot-thumbs" style="list-style:none;margin:0.6rem 0 0;padding:0;">
                    @foreach($auction->images as $photo)
                    <li>
                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener" aria-label="Open photo {{ $loop->iteration }} of {{ $photoCount }} in a new tab">
                            <img src="{{ $photo->url() }}" alt="" loading="lazy" width="72" height="72">
                        </a>
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
            @endif

            @if($auction->description)
            <div class="dot-card" style="padding:1.25rem 1.4rem;margin-bottom:1.25rem;">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#71717a;margin-bottom:0.5rem;">Description</div>
                <p style="font-size:0.85rem;color:#d4d4d8;line-height:1.6;margin:0;">{{ $auction->description }}</p>
            </div>
            @endif

            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.85rem;margin-bottom:1.25rem;">
                <div class="dot-card" style="padding:1rem 1.15rem;">
                    <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:#52525b;margin-bottom:0.4rem;">Starting Price</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:1.05rem;font-weight:700;color:#f4f4f5;">{{ \App\Models\Auction::money($auction->starting_price) }}</div>
                </div>
                <div class="dot-card" style="padding:1rem 1.15rem;">
                    <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:#52525b;margin-bottom:0.4rem;">Bid Increment</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:1.05rem;font-weight:700;color:#f4f4f5;">{{ \App\Models\Auction::money($auction->bid_increment) }}</div>
                </div>
                <div class="dot-card" style="padding:1rem 1.15rem;">
                    <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:#52525b;margin-bottom:0.4rem;">Buy Now</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:1.05rem;font-weight:700;color:{{ $auction->buy_now_price ? '#fcd34d' : '#3f3f46' }};">
                        {{ $auction->buy_now_price ? 'R'.number_format($auction->buy_now_price, 2) : '—' }}
                    </div>
                </div>
            </div>

            @if($auction->items->isNotEmpty())
            <div class="dot-card" style="padding:1.25rem 1.4rem;">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#71717a;margin-bottom:0.75rem;">Items in this lot</div>
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    @foreach($auction->items as $item)
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:0.55rem 0.75rem;border-radius:8px;background:rgba(255,255,255,0.02);">
                        <span style="font-size:0.82rem;color:#e4e4e7;font-weight:500;">{{ $item->name }}</span>
                        <span style="font-size:0.72rem;color:#71717a;">{{ ucfirst($item->condition) }}@if($item->location) · {{ $item->location }}@endif</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        {{-- Right column: bid panel --}}
        <div>
            @auth
                <livewire:auctions.bid-panel :auction="$auction" :key="'bid-'.$auction->id" />
                @if($settlement)
                <div class="dot-panel" style="margin-top:1rem;">
                    <div class="dot-panel-body">
                        <h2 class="dot-section-title">Payment pending</h2>
                        @if($settlement->winner_id === auth()->id())
                            <p class="dot-help" style="font-size:13px;">
                                You won this auction at {{ $settlement->amount() }}. Dot.Auction has not opened payment yet, so nothing is due now.
                                You will be told when the payment step opens.
                            </p>
                        @else
                            <p class="dot-help" style="font-size:13px;">
                                Sold for {{ $settlement->amount() }}. The sale is recorded as awaiting payment; Dot.Auction does not collect payment yet.
                                You will be told when the payment step opens.
                            </p>
                        @endif
                    </div>
                </div>
                @endif
            @else
                <div class="dot-card" style="padding:1.5rem;text-align:center;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:#52525b;display:block;margin-bottom:0.6rem;">lock</span>
                    <div style="font-size:0.82rem;color:#a1a1aa;margin-bottom:1rem;">Log in to place a bid on this auction.</div>
                    <a href="{{ route('login') }}" class="dot-btn dot-btn-primary" style="justify-content:center;">Log In</a>
                </div>
            @endauth
        </div>

    </div>

</div>

</x-app-layout>
