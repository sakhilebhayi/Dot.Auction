{{--
  ds.lot-row: a lot that is closing as a ruled strip, with the clock as the headline figure. For short lists of lots that close soon (the Closing soon row on Browse,
  the lots closing in 24 hours on Home). A strip, not a card: nothing floats, and a list of one fills the width instead of leaving a blank cell.
  Props: auction (required; load with LotBrowser::withCardData), viewer=null (ViewerCue word), size='xl' (the countdown size: lg | xl).
  Slot: actions (the watch control).
--}}
@props(['auction', 'viewer' => null, 'size' => 'xl'])
@php
    use App\Support\ViewerCue;
    $bids = (int) ($auction->bids_count ?? 0);
    $hasBids = $bids > 0;
    $ext = (int) $auction->snipe_extensions;
@endphp
<li {{ $attributes->class(['dc-close', 'dc-close--'.$size]) }}>
    <div class="dc-close__clock">
        <x-ds.countdown :ends="$auction->ends_at" :size="$size" label="Closes in" :show-at="false" stacked />
        <x-ds.rail :starts="$auction->starts_at" :ends="$auction->ends_at" :extensions="$ext" thick class="dc-close__rail" />
    </div>
    <div class="dc-close__lot">
        <h3 class="dc-close__name"><a href="{{ route('auctions.show', $auction) }}" class="dc-close__link">{{ $auction->title }}</a></h3>
        <p class="dc-close__facts">
            <span>{{ $bids }} {{ $bids === 1 ? 'bid' : 'bids' }}</span>
            @if ($ext > 0)<span>Extended {{ $ext }} {{ $ext === 1 ? 'time' : 'times' }}</span>@endif
            @if ($viewer)@php $c = ViewerCue::describe($viewer); @endphp<span class="ds-lot__cue ds-lot__cue--{{ $viewer }}"><x-ds.icon :name="$c['icon']" :size="14" /> {{ $c['word'] }}</span>@endif
        </p>
    </div>
    <div class="dc-close__price">
        <span class="dc-close__price-label">{{ $hasBids ? 'Current bid' : 'Starting bid' }}</span>
        <x-ds.money :amount="$hasBids ? $auction->current_price : $auction->starting_price" :size="$size === 'xl' ? 'xl' : 'lg'" />
    </div>
    @isset($actions)<div class="dc-close__act">{{ $actions }}</div>@endisset
</li>
