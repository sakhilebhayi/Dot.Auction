{{--
  My bids. Four groups by what the person can do next: outbid (bid again), leading (nothing to do but watch the clock), waiting on the seller
  (the lot ended below its reserve), and ended (won, not won, not sold, cancelled). Own rows only (App\Services\Auctions\ViewerActivity).
--}}
@php
    use App\Support\Display;

    $none = $outbid->isEmpty() && $leading->isEmpty() && $waiting->isEmpty() && $ended->isEmpty();
@endphp
<x-app-layout title="My bids" description="Every lot you have bid on, and what to do next." width="wide">
    <x-slot name="header">My bids</x-slot>
    <x-slot name="actions"><x-ds.button :href="route('auctions.index', ['state' => 'live'])" variant="quiet" icon="browse">Find lots to bid on</x-ds.button></x-slot>

    @if ($none)
        <x-ds.empty-state :level="2" title="You have not bid on anything yet" icon="bids" body="Lots you bid on show here, grouped by what to do next: bid again, wait, or pay.">
            <x-ds.button :href="route('auctions.index', ['state' => 'live'])" icon="browse">Browse live lots</x-ds.button>
        </x-ds.empty-state>
    @else
        @if ($outbid->isNotEmpty())
            <x-ds.section class="dc-group" id="outbid" title="Outbid" description="Someone bid higher. The lot is still open, so you can bid again.">
                <x-ds.table caption="Open lots where you were outbid">
                    <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Your bid</th><th scope="col" class="num">Price now</th><th scope="col" class="num">Next valid bid</th><th scope="col">Closes</th><th scope="col" class="actions"><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                        @foreach ($outbid as $lot)
                            <tr>
                                <td data-label="Lot" class="dc-lotcell"><a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a><span class="dc-sub">{{ (int) $lot->bids_count }} {{ (int) $lot->bids_count === 1 ? 'bid' : 'bids' }}</span></td>
                                <td data-label="Your bid" class="num"><x-ds.money :amount="$lot->viewer_top_bid" /></td>
                                <td data-label="Price now" class="num"><x-ds.money :amount="$lot->current_price" /></td>
                                <td data-label="Next valid bid" class="num"><x-ds.money :amount="$lot->minimumBidCents() / 100" /></td>
                                <td data-label="Closes"><x-ds.countdown :ends="$lot->ends_at" size="sm" label="" /></td>
                                <td class="actions"><x-ds.button :href="route('auctions.show', $lot)" size="sm">Bid again</x-ds.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ds.table>
            </x-ds.section>
        @endif

        @if ($leading->isNotEmpty())
            <x-ds.section class="dc-group" id="leading" title="You lead" description="Your bid is the highest. Nothing to do unless someone outbids you.">
                <x-ds.table caption="Open lots where you lead">
                    <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Your bid</th><th scope="col" class="num">Bids</th><th scope="col">Closes</th><th scope="col" class="actions"><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                        @foreach ($leading as $lot)
                            <tr>
                                <td data-label="Lot" class="dc-lotcell"><a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a></td>
                                <td data-label="Your bid" class="num"><x-ds.money :amount="$lot->viewer_top_bid" /></td>
                                <td data-label="Bids" class="num">{{ (int) $lot->bids_count }}</td>
                                <td data-label="Closes"><x-ds.countdown :ends="$lot->ends_at" size="sm" label="" /></td>
                                <td class="actions"><x-ds.button :href="route('auctions.show', $lot)" variant="quiet" size="sm">Open lot</x-ds.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ds.table>
            </x-ds.section>
        @endif

        @if ($waiting->isNotEmpty())
            <x-ds.section class="dc-group" id="waiting" title="Waiting on the seller" description="These lots ended below the seller's reserve with your bid on top. The seller decides whether to accept it.">
                <x-ds.table caption="Lots waiting on the seller">
                    <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Your bid</th><th scope="col">Ended</th><th scope="col" class="actions"><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                        @foreach ($waiting as $lot)
                            <tr>
                                <td data-label="Lot" class="dc-lotcell"><a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a><span class="dc-sub"><x-ds.status state="pending" size="sm" /></span></td>
                                <td data-label="Your bid" class="num"><x-ds.money :amount="$lot->viewer_top_bid" /></td>
                                <td data-label="Ended">{{ Display::time($lot->ends_at, 'D j M, H:i') }}</td>
                                <td class="actions"><x-ds.button :href="route('auctions.show', $lot)" variant="quiet" size="sm">Open lot</x-ds.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ds.table>
            </x-ds.section>
        @endif

        @if ($ended->isNotEmpty())
            <x-ds.section class="dc-group" id="ended" title="Ended" description="Lots that closed. Won lots link to their payment.">
                <x-ds.table caption="Lots you bid on that have ended">
                    <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Your bid</th><th scope="col" class="num">Final price</th><th scope="col">Outcome</th><th scope="col">Ended</th><th scope="col" class="actions"><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                        @foreach ($ended as $lot)
                            @php
                                $lotState = Display::lotState($lot);
                                $won = $lotState === 'sold' && (bool) $lot->viewer_leads;
                                $sale = $lot->settlement;
                            @endphp
                            <tr>
                                <td data-label="Lot" class="dc-lotcell"><a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a></td>
                                <td data-label="Your bid" class="num"><x-ds.money :amount="$lot->viewer_top_bid" /></td>
                                <td data-label="Final price" class="num">@if (in_array($lotState, ['sold', 'unsold', 'pending'], true) && (int) $lot->bids_count > 0)<x-ds.money :amount="$lot->current_price" />@else None @endif</td>
                                <td data-label="Outcome">
                                    @if ($won)<x-ds.badge tone="live" icon="trophy" size="sm">You won</x-ds.badge>
                                        @if ($sale)
                                            @php $position = \App\Support\SettlementPosition::of($sale); @endphp
                                            @if ($position->isOverdue())<span class="dc-sub"><x-ds.status state="payment_overdue" size="sm" /></span>
                                            @elseif ($position->isWaiting())<span class="dc-sub">Payment opens soon</span>@endif
                                        @endif
                                    @elseif ($lotState === 'sold' || ($lotState === 'pending' && ! $lot->viewer_leads))<x-ds.badge tone="neutral" size="sm">Not won</x-ds.badge>
                                    @else<x-ds.status :state="$lotState" size="sm" />@endif
                                </td>
                                <td data-label="Ended">{{ Display::time($lot->ends_at, 'D j M, H:i') }}</td>
                                <td class="actions">
                                    @if ($won && $sale)<x-ds.button :href="route('settlements.show', $sale)" size="sm" :variant="$position->canPayNow() ? 'primary' : 'quiet'">{{ $position->winnerAction() ?? 'View sale' }}</x-ds.button>
                                    @else<x-ds.button :href="route('auctions.show', $lot)" variant="quiet" size="sm">Open lot</x-ds.button>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ds.table>
                <x-ds.pagination :paginator="$ended" noun="ended lots" />
            </x-ds.section>
        @endif
    @endif
</x-app-layout>
