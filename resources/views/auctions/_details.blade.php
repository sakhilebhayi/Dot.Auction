{{--
  What the lot is. Static facts only (nothing here moves while the lot is live, so the page never shows a stale price or time here):
  the description, the items in the lot, and the terms of bidding. Prices and times that change live in the bid panel.
--}}
@php
    use App\Models\Auction;
    $window = (int) config('auction.snipe_window_minutes', 5);
    $extra = (int) config('auction.snipe_extension_minutes', 5);
    $maxExt = (int) config('auction.snipe_max_extensions', 6);
@endphp
<div class="lot-details">
    @if ($auction->description)
        <x-ds.section title="About this lot" id="lot-about">
            <p class="lot-desc">{{ $auction->description }}</p>
        </x-ds.section>
    @endif

    @if ($auction->items->isNotEmpty())
        <x-ds.section title="In this lot" id="lot-items">
            <ul class="lot-items">
                @foreach ($auction->items as $item)
                    <li>
                        <span class="lot-items__name">{{ $item->name }}</span>
                        <span class="lot-items__meta">{{ ucfirst((string) $item->condition) }}@if ($item->location), {{ $item->location }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </x-ds.section>
    @endif

    <x-ds.section title="Terms of this lot" id="lot-terms">
        <x-ds.dl>
            @if ($auction->category)<div class="ds-dl__row"><dt>Category</dt><dd>{{ $auction->category->name }}</dd></div>@endif
            <div class="ds-dl__row"><dt>Seller</dt><dd>{{ $auction->seller->name ?? 'Unknown' }}</dd></div>
            <div class="ds-dl__row"><dt>Starting bid</dt><dd><x-ds.money :amount="$auction->starting_price" size="md" /></dd></div>
            <div class="ds-dl__row"><dt>Bid step</dt><dd><x-ds.money :amount="$auction->bid_increment" size="md" /></dd></div>
            <div class="ds-dl__row"><dt>Buy now</dt><dd>@if ($auction->buy_now_price !== null)<x-ds.money :amount="$auction->buy_now_price" size="md" />@else Not offered @endif</dd></div>
            @if ($window > 0 && $extra > 0)
                <div class="ds-dl__row"><dt>Late bids</dt><dd>A bid in the final {{ $window }} minutes adds {{ $extra }} minutes, up to {{ $maxExt }} times</dd></div>
            @endif
        </x-ds.dl>
    </x-ds.section>
</div>
