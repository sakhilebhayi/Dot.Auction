{{-- My bids: the lots the person has bid on that are open or waiting for a result (outbid first, then soonest close). Every row links to ?lot=<id>#stage, which the
     controller checks against the person's own rows. In: $bidRows, $selected, $selectedKind. --}}
@php use App\Support\Display; @endphp
<x-ds.section title="My bids" id="my-bids" class="dc-sec" description="Lots you have bid on that are open or waiting for a result. Outbid lots come first. Choose a row to see the lot beside it.">
    <table class="dc-tbl" data-sortable>
        <caption class="sr-only">Open lots you have bid on</caption>
        <thead><tr><th scope="col" data-key="title">Lot</th><th scope="col" class="num" data-key="your">Your bid</th><th scope="col" class="num" data-key="now">Now</th><th scope="col" data-key="closes">Closes</th></tr></thead>
        <tbody>
            @foreach ($bidRows->take(6) as $lot)
                @php
                    $leads = (bool) $lot->viewer_leads;
                    $waitingResult = $lot->status === 'pending_review' || ($lot->status === 'active' && $lot->ends_at->isPast());
                    $isSel = $selectedKind === 'bid' && $selected?->id === $lot->id;
                @endphp
                <tr @if ($isSel) aria-current="true" @endif style="--i: {{ $loop->index }}"
                    data-title="{{ $lot->title }}" data-your="{{ $lot->viewer_top_bid }}" data-now="{{ $lot->current_price }}" data-closes="{{ $lot->ends_at->getTimestamp() }}">
                    <td class="dc-lotcell"><div class="dc-lotcell__in">
                        @include('dashboard._thumb', ['lot' => $lot])
                        <span class="dc-lotcell__txt">
                            <a class="dc-lotname" href="{{ route('dashboard', ['lot' => $lot->id]) }}#stage">{{ $lot->title }}</a>
                            <span class="dc-sub">
                                @if ($waitingResult)<x-ds.status :state="Display::lotState($lot)" size="sm" />
                                @elseif ($leads)<x-ds.badge tone="live" icon="check-circle" size="sm">You lead</x-ds.badge>
                                @else<x-ds.badge tone="urgent" icon="alert" size="sm">Outbid</x-ds.badge>@endif
                            </span>
                        </span>
                    </div></td>
                    <td data-label="Your bid" class="num"><x-ds.money :amount="$lot->viewer_top_bid" /></td>
                    <td data-label="Now" class="num"><x-ds.money :amount="$lot->current_price" /></td>
                    <td data-label="Closes">@if ($waitingResult)<span class="dc-sub">Closed {{ Display::time($lot->ends_at, 'D j M, H:i') }}</span>@else<x-ds.countdown :ends="$lot->ends_at" size="sm" label="" />@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="dc-more"><a class="ds-btn ds-btn--link ds-btn--sm" href="{{ auction_route('buyer.bids') }}"><span class="ds-btn__label">{{ $bidRows->count() > 6 ? 'All '.$bidRows->count().' lots I have bid on' : 'My bids, including ended lots' }}</span></a></p>
</x-ds.section>
