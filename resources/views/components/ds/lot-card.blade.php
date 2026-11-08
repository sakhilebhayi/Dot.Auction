{{--
  ds.lot-card: one lot as a catalogue entry. Sits in a ruled grid (.ds-catalogue), never as a rounded card floating in a gutter.
  Props: auction (App\Models\Auction, required; use withCount('bids') and with(['images','winningBid']) to avoid N+1),
         href=null (default auctions.show), watching=null (true shows the word "Watching"; null hides it), compact=false (no rail, no bid count).
  Shows: first photo (or a labelled placeholder), the lot number as a paddle tab, the status word, the price with the right label for the state
  (Starting bid, Current bid, Sold for, ...), bid count, the closing clock and, while live, the closing rail.
  Reserve amounts are never shown here; only the seller's own pages read reserve_price.
--}}
@props(['auction', 'href' => null, 'watching' => null, 'compact' => false])
@php
    use App\Support\Display;
    $state = Display::lotState($auction);
    $link = $href ?? Display::route('auctions.show', $auction);
    $image = $auction->images->first();
    $bids = $auction->bids_count ?? null;
    $hasBids = $bids !== null ? $bids > 0 : \App\Models\Auction::toCents($auction->current_price) > \App\Models\Auction::toCents($auction->starting_price);
    [$priceLabel, $price] = match ($state) {
        'sold' => ['Sold for', $auction->current_price],
        'unsold' => [$hasBids ? 'Highest bid' : 'No bids', $hasBids ? $auction->current_price : $auction->starting_price],
        'pending' => ['Highest bid', $auction->current_price],
        'scheduled', 'draft', 'cancelled' => ['Starting bid', $auction->starting_price],
        default => [$hasBids ? 'Current bid' : 'Starting bid', $auction->current_price],
    };
    $live = in_array($state, ['live', 'ending'], true);
    $lot = str_pad((string) $auction->id, 4, '0', STR_PAD_LEFT);
@endphp
<article {{ $attributes->class(['ds-lot', 'ds-lot--'.$state]) }}>
    <div class="ds-lot__media">
        @if ($image)
            <img src="{{ Display::route('auctions.images.show', [$auction, $image]) }}" alt="" loading="lazy" decoding="async" width="640" height="480">
        @else
            <span class="ds-lot__noimg"><x-ds.icon name="gavel" :size="28" /><span>No photo yet</span></span>
        @endif
        <span class="ds-lot__number"><span class="sr-only">Lot </span>{{ $lot }}</span>
        @if ($live && ! $compact)<x-ds.rail :starts="$auction->starts_at" :ends="$auction->ends_at" :extensions="(int) $auction->snipe_extensions" class="ds-lot__rail" />@endif
    </div>
    <div class="ds-lot__body">
        <div class="ds-lot__tags">
            <x-ds.status :state="$state" size="sm" />
            @if ($live && (int) $auction->snipe_extensions > 0)<x-ds.status state="extended" size="sm" :count="(int) $auction->snipe_extensions" />@endif
            @if ($watching)<span class="ds-lot__watching"><x-ds.icon name="bookmark" :size="14" filled /> Watching</span>@endif
        </div>
        <h3 class="ds-lot__title"><a href="{{ $link }}" class="ds-lot__link">{{ $auction->title }}</a></h3>
        <div class="ds-lot__price">
            <span class="ds-lot__price-label">{{ $priceLabel }}</span>
            <x-ds.money :amount="$price" size="lg" :muted="in_array($state, ['cancelled', 'draft'], true)" />
        </div>
        <div class="ds-lot__meta">
            @if ($live)
                <x-ds.countdown :ends="$auction->ends_at" size="sm" :show-at="false" />
            @elseif ($state === 'scheduled')
                <x-ds.countdown :ends="$auction->ends_at" :starts="$auction->starts_at" size="sm" :show-at="false" />
            @elseif (in_array($state, ['sold', 'unsold', 'pending'], true))
                <span class="ds-lot__when">Closed {{ Display::time($auction->ends_at, 'j M, H:i') }}</span>
            @endif
            @if (! $compact && $bids !== null)<span class="ds-lot__bids">{{ $bids }} {{ $bids === 1 ? 'bid' : 'bids' }}</span>@endif
        </div>
    </div>
</article>
