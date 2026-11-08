{{--
  Home. Everything shown is read from the signed-in person's own rows (App\Http\Controllers\DashboardController): a person can be buyer, seller and
  operator at once, so there is no role switch. The first thing on the page is what needs them, in words, with a direct link; after that only the
  sections that have data. A new account gets a welcome with the two real paths instead of a page of zeros.
--}}
@php
    use App\Support\Display;
    use App\Support\ViewerCue;
    $watchPlural = fn (int $n) => $n === 1 ? 'lot' : 'lots';
@endphp
<x-app-layout title="Home" :description="$summary ?? null" width="wide">
    <x-slot name="header">Home</x-slot>
    @unless ($isNew)
        <x-slot name="actions">
            <x-ds.button :href="route('auctions.index')" variant="quiet" icon="browse">Browse lots</x-ds.button>
            <x-ds.button :href="route('seller.auctions.create')" icon="plus">List a lot</x-ds.button>
        </x-slot>
    @endunless

    @if ($isNew)
        <x-ds.section title="Two ways to start" id="start">
            <div class="dc-paths">
                <div class="dc-path">
                    <h3 class="dc-path__title"><x-ds.icon name="bids" :size="24" /> Find a lot and bid</h3>
                    <p class="dc-path__body">Every lot shows its price, the next valid bid and when it closes. Place a bid when you are ready; you are told straight away if someone outbids you.</p>
                    <x-ds.button :href="route('auctions.index')" icon-right="arrow-right">Browse lots</x-ds.button>
                </div>
                <div class="dc-path">
                    <h3 class="dc-path__title"><x-ds.icon name="tag" :size="24" /> List something to sell</h3>
                    <p class="dc-path__body">Describe it, add photos, set the starting price and when the auction closes. It stays a private draft until you publish it.</p>
                    <x-ds.button :href="route('seller.auctions.create')" variant="quiet" icon-right="arrow-right">List a lot</x-ds.button>
                </div>
            </div>
        </x-ds.section>

        @if ($endingSoon->isNotEmpty())
            <x-ds.section title="Closing in the next 24 hours" id="closing">
                <x-slot name="actions"><x-ds.button :href="route('auctions.index', ['state' => 'live'])" variant="link" size="sm">All live lots</x-ds.button></x-slot>
                @include('dashboard._catalogue', ['lots' => $endingSoon, 'userId' => auth()->id()])
            </x-ds.section>
        @endif
    @else
        {{-- ───────── What needs you ───────── --}}
        <x-ds.section title="Needs you" id="needs">
            @if (count($needs))
                <ul class="dc-needs">
                    @foreach ($needs as $need)
                        <li class="dc-need dc-need--{{ $need['tone'] }}">
                            <x-ds.icon :name="$need['icon']" :size="22" class="dc-need__icon" />
                            <p class="dc-need__text">{{ $need['text'] }}</p>
                            @if ($need['action'])<x-ds.button :href="$need['href']" variant="quiet" size="sm" class="dc-need__action">{{ $need['action'] }}</x-ds.button>@endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="dc-calm"><x-ds.icon name="check-circle" :size="22" /> Nothing needs you right now.</p>
            @endif
        </x-ds.section>

        {{-- The reserve decision is the seller's own component; it stays reachable here. --}}
        @if ($decisions->isNotEmpty())
            <x-ds.section title="Awaiting your decision" id="decisions" description="These lots ended below your reserve. Accept the top bid to make the sale, or end the lot unsold.">
                @foreach ($decisions as $proposal)
                    <livewire:auctions.reserve-decision-panel :proposal="$proposal" :key="'proposal-'.$proposal->id" />
                @endforeach
            </x-ds.section>
        @endif

        {{-- ───────── My bids and sales to pay ───────── --}}
        @if ($openBids->isNotEmpty() || $salesToPay->isNotEmpty())
            <div class="dc-split">
                @if ($openBids->isNotEmpty())
                    <x-ds.section title="My bids" id="my-bids" description="Lots still open that you have bid on. Outbid lots come first.">
                        <x-ds.table caption="Open lots you have bid on">
                            <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Your bid</th><th scope="col" class="num">Now</th><th scope="col">Closes</th></tr></thead>
                            <tbody>
                                @foreach ($openBids->take(6) as $lot)
                                    @php $leads = (bool) $lot->viewer_leads; @endphp
                                    <tr>
                                        <td data-label="Lot" class="dc-lotcell">
                                            <a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a>
                                            <span class="dc-sub">
                                                @if ($leads)<x-ds.badge tone="live" icon="check-circle" size="sm">You lead</x-ds.badge>
                                                @else<x-ds.badge tone="urgent" icon="alert" size="sm">Outbid</x-ds.badge>@endif
                                            </span>
                                        </td>
                                        <td data-label="Your bid" class="num"><x-ds.money :amount="$lot->viewer_top_bid" /></td>
                                        <td data-label="Now" class="num"><x-ds.money :amount="$lot->current_price" /></td>
                                        <td data-label="Closes"><x-ds.countdown :ends="$lot->ends_at" size="sm" label="" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ds.table>
                        @if ($openBids->count() > 6)
                            <p class="dc-more"><a class="ds-btn ds-btn--link ds-btn--sm" href="{{ auction_route('buyer.bids') }}"><span class="ds-btn__label">All {{ $openBids->count() }} open bids</span></a></p>
                        @else
                            <p class="dc-more"><a class="ds-btn ds-btn--link ds-btn--sm" href="{{ auction_route('buyer.bids') }}"><span class="ds-btn__label">My bids, including ended lots</span></a></p>
                        @endif
                    </x-ds.section>
                @endif

                @if ($salesToPay->isNotEmpty())
                    <x-ds.section title="Won and to pay" id="to-pay" description="Lots you won that are not paid for yet.">
                        <x-ds.table caption="Won lots waiting for payment">
                            <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Amount</th><th scope="col">Deadline</th><th scope="col" class="actions"><span class="sr-only">Action</span></th></tr></thead>
                            <tbody>
                                @foreach ($salesToPay->take(6) as $position)
                                    @php $sale = $position->settlement; @endphp
                                    <tr>
                                        <td data-label="Lot" class="dc-lotcell">
                                            <a class="dc-lotname" href="{{ route('settlements.show', $sale) }}">{{ $sale->auction->title }}</a>
                                            @if ($position->isOverdue())<span class="dc-sub"><x-ds.status state="payment_overdue" size="sm" /></span>@endif
                                        </td>
                                        <td data-label="Amount" class="num"><x-ds.money :amount="$sale->amount_cents / 100" /></td>
                                        <td data-label="Deadline">{{ $position->canPayNow() ? ($sale->payment_due_at ? Display::time($sale->payment_due_at, 'D j M, H:i') : 'Not set') : 'Opens soon' }}</td>
                                        <td class="actions"><x-ds.button :href="route('settlements.show', $sale)" size="sm" :variant="$position->canPayNow() ? 'primary' : 'quiet'">{{ $position->winnerAction() ?? 'View sale' }}</x-ds.button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ds.table>
                        <p class="dc-more"><a class="ds-btn ds-btn--link ds-btn--sm" href="{{ auction_route('buyer.won') }}"><span class="ds-btn__label">All the lots I have won</span></a></p>
                    </x-ds.section>
                @endif
            </div>
        @endif

        {{-- ───────── What I am selling ───────── --}}
        @if ($seller && ($seller['live'] || $seller['scheduled'] || $seller['pending'] || $seller['drafts']))
            <x-ds.section title="My lots" id="selling">
                <x-slot name="actions"><x-ds.button :href="route('seller.auctions.index')" variant="quiet" size="sm">Manage my lots</x-ds.button></x-slot>
                @if ($sellingNow->isNotEmpty())
                    <x-ds.table caption="Your lots that are open for bids">
                        <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Price now</th><th scope="col" class="num">Bids</th><th scope="col">Closes</th></tr></thead>
                        <tbody>
                            @foreach ($sellingNow as $lot)
                                @php $lotState = Display::lotState($lot); @endphp
                                <tr>
                                    <td data-label="Lot" class="dc-lotcell">
                                        <a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a>
                                        <span class="dc-sub"><x-ds.status :state="$lotState" size="sm" />@if ((int) $lot->snipe_extensions > 0) <x-ds.status state="extended" size="sm" :count="(int) $lot->snipe_extensions" />@endif</span>
                                    </td>
                                    <td data-label="Price now" class="num"><x-ds.money :amount="$lot->current_price" /></td>
                                    <td data-label="Bids" class="num">{{ (int) $lot->bids_count }}</td>
                                    <td data-label="Closes"><x-ds.countdown :ends="$lot->ends_at" size="sm" label="" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ds.table>
                @endif
                <x-ds.dl layout="inline" class="dc-ledger">
                    @if ($seller['live'])<div class="ds-dl__row"><dt>Open for bids</dt><dd class="num">{{ $seller['live'] }}</dd></div>@endif
                    @if ($seller['closing_soon'])<div class="ds-dl__row"><dt>Closing within 24 hours</dt><dd class="num">{{ $seller['closing_soon'] }}</dd></div>@endif
                    @if ($seller['pending'])<div class="ds-dl__row"><dt>Awaiting your decision</dt><dd class="num"><a href="#decisions">{{ $seller['pending'] }}</a></dd></div>@endif
                    @if ($seller['scheduled'])<div class="ds-dl__row"><dt>Scheduled</dt><dd class="num">{{ $seller['scheduled'] }}</dd></div>@endif
                    @if ($seller['drafts'])<div class="ds-dl__row"><dt>Drafts, not published</dt><dd class="num">{{ $seller['drafts'] }}</dd></div>@endif
                </x-ds.dl>
            </x-ds.section>
        @endif

        {{-- ───────── Watching, closing soon ───────── --}}
        @if ($watchedSoon->isNotEmpty())
            <x-ds.section title="Watching, closing in the next 24 hours" id="watching">
                <x-slot name="actions"><x-ds.button :href="auction_route('buyer.watchlist')" variant="quiet" size="sm">All {{ $watchingCount }} watched {{ $watchPlural($watchingCount) }}</x-ds.button></x-slot>
                @include('dashboard._catalogue', ['lots' => $watchedSoon, 'userId' => auth()->id()])
            </x-ds.section>
        @endif

        {{-- ───────── Closing soon on the platform ───────── --}}
        @if ($endingSoon->isNotEmpty())
            <x-ds.section title="Closing in the next 24 hours" id="closing">
                <x-slot name="actions"><x-ds.button :href="route('auctions.index', ['state' => 'live'])" variant="quiet" size="sm">All live lots</x-ds.button></x-slot>
                @include('dashboard._catalogue', ['lots' => $endingSoon, 'userId' => auth()->id()])
            </x-ds.section>
        @endif
    @endif
</x-app-layout>
