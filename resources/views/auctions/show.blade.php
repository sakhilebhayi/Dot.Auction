{{--
  The lot page. On a phone the order is: title, the bidding column (status, price, clock, what it means for you, the bid form), the photos, the facts,
  the bid history. From 56rem of page width the photos and facts sit on the left and the bidding column on the right, so the price and the form stay in view.
  The bidding column is one Livewire component (BidPanel) that keeps itself current; the rest of the page is static.
--}}
@php
    $isSeller = auth()->id() === $auction->seller_id;
    $lotNo = str_pad((string) $auction->id, 4, '0', STR_PAD_LEFT);
@endphp
<x-app-layout
    :title="$auction->title"
    :description="'Lot '.$lotNo.' on Dot.Auction: '.\Illuminate\Support\Str::limit(strip_tags((string) $auction->description) ?: $auction->title, 120)"
    :back="route('auctions.index')" back-label="All lots"
>
    {{-- No header slot: the layout prints the title prop as the h1, escaped once. A header slot would escape a lot title that contains ' & or " twice. --}}
    <x-slot name="meta">
        <span class="lot-no"><span class="sr-only">Lot </span>{{ $lotNo }}</span>
        @if ($auction->category)<span class="lot-cat">{{ $auction->category->name }}</span>@endif
    </x-slot>
    @if ($isSeller)
    <x-slot name="actions">
            @can('update', $auction)
                <x-ds.button variant="quiet" icon="pencil" :href="route('seller.auctions.edit', $auction)">{{ $auction->status === 'draft' ? 'Edit draft' : 'Edit lot' }}</x-ds.button>
            @endcan
            <x-ds.button variant="quiet" :href="route('seller.auctions.index')">Manage your lots</x-ds.button>
    </x-slot>
    @endif

    <div class="lot-wrap"><div class="lot">
        <div class="lot__side">
            <livewire:auctions.bid-panel :auction="$auction" :key="'bid-'.$auction->id" />
        </div>
        <div class="lot__gallery">@include('auctions._gallery', ['images' => $auction->images])</div>
        <div class="lot__details">@include('auctions._details')</div>
    </div></div>
</x-app-layout>
