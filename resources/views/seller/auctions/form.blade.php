@php
    $editing = $auction->exists;
    $locked = $editing && $termsLocked;
    $status = $editing ? $auction->displayStatus() : 'draft';
    $symbol = config('auction.currency_symbol');
    $minHours = max(1, intdiv((int) config('auction.listing.min_duration_minutes'), 60));
    $snipeWindow = (int) config('auction.snipe_window_minutes');
    $snipeExtension = (int) config('auction.snipe_extension_minutes');
    $snipeMax = (int) config('auction.snipe_max_extensions');
@endphp
<x-app-layout>
<div class="dot-page dot-page-narrow">

    <p style="margin:0 0 1.25rem;">
        <a href="{{ route('seller.auctions.index') }}" class="dot-link">&larr; My Auctions</a>
    </p>

    <div class="dot-row dot-row-between" style="margin-bottom:1.5rem;">
        <div>
            <h1 class="dot-title">{{ $editing ? 'Edit auction' : 'List an auction' }}</h1>
            <div class="dot-subtitle">
                @if(! $editing)
                    This saves a private draft. Nothing is visible to buyers until you publish it.
                @elseif($status === 'draft')
                    Draft &middot; not visible to buyers.
                @else
                    {{ $auction->title }}
                @endif
            </div>
        </div>
        @if($editing)
            <span class="dot-status dot-status-{{ $status }}">{{ ucfirst($status) }}</span>
        @endif
    </div>

    @include('seller.auctions._notices')

    @if($locked)
        <div class="dot-alert dot-alert-info" role="note">
            A bid has been placed, so the price, reserve, closing time and other terms are locked for the buyers' sake.
            You can still update the description and manage photos.
        </div>

        <div class="dot-panel" style="margin-bottom:1.5rem;">
            <div class="dot-panel-head"><h2 class="dot-section-title">Locked terms</h2></div>
            <dl class="dot-panel-body dot-form-grid" style="margin:0;">
                <div><dt class="dot-label">Title</dt><dd style="margin:0;">{{ $auction->title }}</dd></div>
                <div><dt class="dot-label">Category</dt><dd style="margin:0;">{{ $auction->category->name ?? 'None' }}</dd></div>
                <div><dt class="dot-label">Starting price</dt><dd class="dot-mono" style="margin:0;">{{ \App\Models\Auction::money($auction->starting_price) }}</dd></div>
                <div><dt class="dot-label">Bid increment</dt><dd class="dot-mono" style="margin:0;">{{ \App\Models\Auction::money($auction->bid_increment) }}</dd></div>
                <div><dt class="dot-label">Reserve price</dt><dd class="dot-mono" style="margin:0;">{{ $auction->reserve_price ? \App\Models\Auction::money($auction->reserve_price) : 'None' }}</dd></div>
                <div><dt class="dot-label">Buy now price</dt><dd class="dot-mono" style="margin:0;">{{ $auction->buy_now_price ? \App\Models\Auction::money($auction->buy_now_price) : 'None' }}</dd></div>
                <div><dt class="dot-label">Closes</dt><dd style="margin:0;">{{ $auction->ends_at->format('M j, Y H:i') }} ({{ config('app.timezone') }})</dd></div>
            </dl>
        </div>
    @endif

    <form method="POST" action="{{ $editing ? route('seller.auctions.update', $auction) : route('seller.auctions.store') }}" novalidate class="dot-panel" style="margin-bottom:1.5rem;">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="dot-panel-head"><h2 class="dot-section-title">{{ $locked ? 'Description' : 'Details' }}</h2></div>
        <div class="dot-panel-body">
            <div class="dot-form-grid">
                @unless($locked)
                    <x-seller.field name="title" label="Title" required span maxlength="120" :value="$values['title']" />
                @endunless

                <x-seller.field name="description" type="textarea" label="Description" span maxlength="5000"
                    :value="$values['description']"
                    help="What it is, its condition, what is included. Keep it factual: buyers rely on it." />

                @unless($locked)
                    <x-seller.field name="category_id" type="select" label="Category" required placeholder="Choose a category"
                        :options="$categories" :value="$values['category_id']" />
                    <div></div>

                    <x-seller.field name="starting_price" label="Starting price" required :prefix="$symbol" inputmode="decimal" autocomplete="off"
                        :value="$values['starting_price']"
                        help="Bidding starts here. The first bid must beat it by one increment." />
                    <x-seller.field name="bid_increment" label="Bid increment" required :prefix="$symbol" inputmode="decimal" autocomplete="off"
                        :value="$values['bid_increment']"
                        help="How much higher than the current price each new bid must be." />
                    <x-seller.field name="reserve_price" label="Reserve price" :prefix="$symbol" inputmode="decimal" autocomplete="off"
                        :value="$values['reserve_price']"
                        help="The lowest price you will accept. Buyers see only whether it has been met, never the amount. At least the starting price." />
                    <x-seller.field name="buy_now_price" label="Buy now price" :prefix="$symbol" inputmode="decimal" autocomplete="off"
                        :value="$values['buy_now_price']"
                        help="Lets a buyer end the auction at once at this price. It goes away once bidding reaches it. At least one increment above the starting price and not below the reserve." />

                    @if(! $auction->exists || $auction->status === 'draft' || $auction->isScheduled())
                        <x-seller.field name="starts_at" type="datetime-local" label="Opens"
                            :value="$values['starts_at']"
                            help="Leave blank to open as soon as you publish. Times are in {{ config('app.timezone') }}." />
                    @else
                        <div>
                            <span class="dot-label">Opened</span>
                            <p style="margin:0;">{{ $auction->starts_at->format('M j, Y H:i') }}</p>
                            <p class="dot-help">An auction that has opened keeps its opening time.</p>
                        </div>
                    @endif
                    <x-seller.field name="ends_at" type="datetime-local" label="Closes" required
                        :value="$values['ends_at']"
                        :help="'At least '.$minHours.' hour'.($minHours === 1 ? '' : 's').' and at most '.config('auction.listing.max_duration_days').' days after it opens. '.($snipeWindow > 0 && $snipeMax > 0 ? 'A bid in the final '.$snipeWindow.' minutes moves the close '.$snipeExtension.' minutes later, up to '.$snipeMax.' times.' : '')" />
                @endunless
            </div>

            <div class="dot-row" style="margin-top:1.5rem;">
                <button type="submit" class="dot-btn dot-btn-primary">{{ $editing ? 'Save changes' : 'Save draft' }}</button>
                <a href="{{ route('seller.auctions.index') }}" class="dot-btn dot-btn-ghost">{{ $editing ? 'Back' : 'Cancel' }}</a>
            </div>
        </div>
    </form>

    @if($editing)
        <div class="dot-panel" style="margin-bottom:1.5rem;">
            <div class="dot-panel-head"><h2 class="dot-section-title">Status</h2></div>
            <div class="dot-panel-body">
                @if($auction->status === 'draft')
                    <p style="margin:0 0 1rem;">This draft is private. Publishing makes it visible to buyers and opens bidding at the opening time (right away if none is set). Check the details above are saved first.</p>
                    <div class="dot-actions">
                        <form method="POST" action="{{ route('seller.auctions.publish', $auction) }}">
                            @csrf
                            <button type="submit" class="dot-btn dot-btn-primary">Publish auction</button>
                        </form>
                        <form method="POST" action="{{ route('seller.auctions.destroy', $auction) }}" onsubmit="return confirm('Delete this draft permanently?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="dot-btn dot-btn-danger">Delete draft</button>
                        </form>
                    </div>
                @else
                    <p style="margin:0 0 1rem;">
                        This auction is {{ $status === 'scheduled' ? 'scheduled to open '.$auction->starts_at->format('M j, Y H:i') : 'live' }}.
                        <a class="dot-link" href="{{ route('auctions.show', $auction) }}">View the public page</a>.
                    </p>
                    @if($locked)
                        <p class="dot-help">It has bids, so it can no longer be cancelled.</p>
                    @else
                        <form method="POST" action="{{ route('seller.auctions.cancel', $auction) }}" onsubmit="return confirm('Cancel this auction? Buyers will no longer be able to bid.');">
                            @csrf
                            <button type="submit" class="dot-btn dot-btn-danger">Cancel auction</button>
                        </form>
                        <p class="dot-help">Possible only while it has no bids.</p>
                    @endif
                @endif
            </div>
        </div>
    @endif

</div>
</x-app-layout>
