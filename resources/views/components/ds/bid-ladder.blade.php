{{--
  ds.bid-ladder: the signature element. The real price steps of this lot as a ladder: the buy-now rung (if it is still available), the next valid bids,
  the current price, the viewer's own bid and the starting price. Every rung is a number the backend would accept or has recorded; nothing is invented.
  Props: auction (App\Models\Auction, required), yourBid=null (the viewer's highest bid on this lot, major units), leading=false (the viewer holds the current bid),
         steps=3 (how many valid next bids to draw above the current price), interactive=true (the next-bid rungs are buttons that fill [data-ds-bid-input]; false draws them as plain text, e.g. for guests and the welcome example).
  The reserve is confidential: only "Reserve met" / "Reserve not met" is printed, never an amount and never a position on the ladder.
  Put the bid field in the same region and give it data-ds-bid-input; pressing a rung copies its amount there and focuses the field.
  Closed lots: pass interactive=false; the ladder becomes the record of where the hammer stopped (the current rung reads "Sold for" or "Highest bid").
--}}
@props(['auction', 'yourBid' => null, 'leading' => false, 'steps' => 3, 'interactive' => true])
@php
    use App\Models\Auction;
    use App\Support\Display;
    $cur = Auction::toCents($auction->current_price);
    $start = Auction::toCents($auction->starting_price);
    $inc = max(1, Auction::toCents($auction->bid_increment));
    $min = $auction->minimumBidCents();
    $state = Display::lotState($auction);
    $live = in_array($state, ['live', 'ending'], true);
    $open = $live;
    $buyNow = $auction->buyNowAvailable() ? Auction::toCents($auction->buy_now_price) : null;
    $mine = $yourBid !== null ? Auction::toCents($yourBid) : null;
    $hasBids = $cur > $start;
    $rungs = [];
    if ($open && $buyNow !== null) {
        $rungs[] = ['kind' => 'buynow', 'cents' => $buyNow, 'label' => 'Buy now'];
    }
    if ($open) {
        $above = [];
        for ($i = 0; $i < max(1, (int) $steps); $i++) {
            $c = $min + $i * $inc;
            if ($buyNow !== null && $c >= $buyNow) { break; }
            $above[] = ['kind' => $i === 0 ? 'next' : 'step', 'cents' => $c, 'label' => $i === 0 ? 'Next bid' : null];
        }
        $rungs = array_merge($rungs, array_reverse($above));
    }
    $currentLabel = match (true) {
        $state === 'sold' => 'Sold for',
        in_array($state, ['unsold', 'pending', 'closing'], true) && $hasBids => 'Highest bid',
        $hasBids => 'Current bid',
        default => 'Starting bid',
    };
    $rungs[] = ['kind' => 'current', 'cents' => $cur, 'label' => $currentLabel, 'you' => $leading && $mine === $cur];
    if ($mine !== null && $mine < $cur) {
        $rungs[] = ['kind' => 'yours', 'cents' => $mine, 'label' => 'Your bid, outbid', 'you' => true];
    }
    if ($hasBids) {
        $rungs[] = ['kind' => 'start', 'cents' => $start, 'label' => 'Starting bid'];
    }
    $reserveSet = $auction->reserve_price !== null;
@endphp
<div {{ $attributes->class('ds-ladder-wrap') }}>
    <ol class="ds-ladder" aria-label="Bid ladder, highest step first">
        @foreach ($rungs as $r)
            @php
                $amount = Auction::fromCents($r['cents']);
                $press = $interactive && $open && in_array($r['kind'], ['next', 'step'], true);
            @endphp
            <li class="ds-rung ds-rung--{{ $r['kind'] }} @if (! empty($r['you'])) is-you @endif">
                @if ($press)
                    {{-- Its name is what it shows, "Bid R1,300.00" or "Next bid R1,300.00". Pressing fills the bid field; nothing is sent until the form is. --}}
                    <button type="button" class="ds-rung__row" data-ds-rung="{{ $amount }}">
                        <span class="ds-rung__label">{{ $r['kind'] === 'next' ? 'Next bid' : 'Bid' }}</span>
                        <x-ds.money :amount="$amount" :size="$r['kind'] === 'next' ? 'lg' : 'md'" />
                        <x-ds.icon name="arrow-right" :size="16" class="ds-rung__go" />
                    </button>
                @else
                    <div class="ds-rung__row">
                        <span class="ds-rung__label">{{ $r['label'] ?? 'Valid bid' }}@if (! empty($r['you']) && $r['kind'] === 'current')<span class="sr-only">, you lead</span>@endif</span>
                        <x-ds.money :amount="$amount" :size="$r['kind'] === 'current' ? 'xl' : 'md'" :muted="$r['kind'] === 'start'" />
                    </div>
                @endif
                @if (! empty($r['you']))<span class="ds-rung__you">You</span>@endif
            </li>
        @endforeach
    </ol>
    @if ($reserveSet)
        <p class="ds-ladder__reserve {{ $auction->reserveMet() ? 'is-met' : 'is-unmet' }}">
            <x-ds.icon :name="$auction->reserveMet() ? 'check-circle' : 'minus'" :size="16" />
            <span>{{ $auction->reserveMet() ? 'Reserve met' : 'Reserve not met' }}</span>
        </p>
    @endif
</div>
