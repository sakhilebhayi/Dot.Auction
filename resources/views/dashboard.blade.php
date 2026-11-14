{{--
  Home. Everything shown is read from the signed-in person's own rows (App\Http\Controllers\DashboardController): a person can be buyer, seller and
  operator at once, so there is no role switch. Top to bottom: the arrival band (one sentence, one gold action), what needs them in words with a direct link,
  the master-detail pair (a table of their lots and the selected lot beside it), what they owe, and what closes soon. Only sections that have data render.
  A new account gets the two real paths and the floor's next closing lot instead of a page of zeros.
--}}
@php
    use App\Support\Display;
    $watchPlural = fn (int $n) => $n === 1 ? 'lot' : 'lots';
    $isSeller = $landing['role'] === 'seller';
    $zone = config('auction.display_timezone', 'Africa/Johannesburg') === 'Africa/Johannesburg' ? 'South African time' : config('auction.display_timezone');
@endphp
<x-app-layout title="Home" :description="$summary ?? null" width="wide">
    {{-- The arrival band owns the h1 and the page's one dominant action; "Browse lots" and "List a lot" both live there, one of them primary. --}}
    <x-slot name="hero">@include('dashboard._landing')</x-slot>

    @if ($isNew)
        <div class="dc-desk">
            <div class="dc-desk__tables">
                <x-ds.section title="Two ways to start" id="start" class="dc-sec">
                    <ul class="dc-rows">
                        <li class="dc-path">
                            <div>
                                <h3 class="dc-path__title"><x-ds.icon name="bids" :size="20" /> Find a lot and bid</h3>
                                <p class="dc-path__body">Every lot shows its price, the next valid bid and when it closes. Place a bid when you are ready; you are told straight away if someone outbids you.</p>
                            </div>
                            <x-ds.button :href="route('auctions.index')" variant="quiet" icon-right="arrow-right">Browse lots</x-ds.button>
                        </li>
                        <li class="dc-path">
                            <div>
                                <h3 class="dc-path__title"><x-ds.icon name="tag" :size="20" /> List something to sell</h3>
                                <p class="dc-path__body">Describe it, add photos, set the starting price and when the auction closes. It stays a private draft until you publish it.</p>
                            </div>
                            <x-ds.button :href="route('seller.auctions.create')" variant="quiet" icon-right="arrow-right">List a lot</x-ds.button>
                        </li>
                    </ul>
                </x-ds.section>

                @if ($endingSoon->isNotEmpty())
                    <x-ds.section title="Closing in the next 24 hours" id="closing" class="dc-sec">
                        <x-slot name="actions"><x-ds.button :href="route('auctions.index', ['state' => 'live'])" variant="link" size="sm">All live lots</x-ds.button></x-slot>
                        @include('dashboard._catalogue', ['lots' => $endingSoon, 'userId' => auth()->id()])
                    </x-ds.section>
                @endif
            </div>
            @include('dashboard._stage', ['lot' => $landing['lot'], 'kind' => $landing['lot'] ? 'floor' : null, 'isSeller' => false])
        </div>

        <footer class="dc-foot">
            @if ((int) $landing['open'] > 0)<dl><div><dt>Lots open for bids on the floor</dt><dd>{{ (int) $landing['open'] }}</dd></div></dl>@endif
            <p>Times shown in {{ $zone }}</p>
        </footer>
    @else
        {{-- ───────── What needs you ───────── --}}
        <x-ds.section title="Needs you" id="needs" class="dc-sec">
            @if (count($needs))
                <x-slot name="actions"><span class="dc-meta">{{ count($needs) }} {{ count($needs) === 1 ? 'item' : 'items' }}</span></x-slot>
                <ul class="dc-rows dc-needs">
                    @foreach ($needs as $need)
                        <li class="dc-need dc-need--{{ $need['tone'] }}" style="--i: {{ $loop->index }}">
                            <span class="dc-need__tag"><x-ds.badge :tone="$need['tone'] === 'info' ? 'neutral' : $need['tone']" :icon="$need['icon']" size="sm">{{ $need['label'] }}</x-ds.badge></span>
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
            <x-ds.section title="Awaiting your decision" id="decisions" class="dc-sec" description="These lots ended below your reserve. Accept the top bid to make the sale, or end the lot unsold.">
                @foreach ($decisions as $proposal)
                    <livewire:auctions.reserve-decision-panel :proposal="$proposal" :key="'proposal-'.$proposal->id" />
                @endforeach
            </x-ds.section>
        @endif

        {{-- ───────── The desk: my lots on the left, the selected lot beside them ───────── --}}
        @php
            $hasBids = $bidRows->isNotEmpty();
            $hasLots = $seller && ($seller['live'] || $seller['scheduled'] || $seller['pending'] || $seller['drafts']);
            $sections = $isSeller ? ['lots', 'bids'] : ['bids', 'lots'];
        @endphp
        @if ($hasBids || $hasLots || $selected)
            <div class="dc-desk {{ $hasBids || $hasLots ? '' : 'dc-desk--solo' }}">
                @if ($hasBids || $hasLots)
                <div class="dc-desk__tables">
                    @foreach ($sections as $which)
                        @if ($which === 'bids' && $hasBids)
                            @include('dashboard._bids')
                        @elseif ($which === 'lots' && $hasLots)
                            @include('dashboard._lots')
                        @endif
                    @endforeach
                </div>
                @endif
                @include('dashboard._stage', ['lot' => $selected, 'kind' => $selectedKind, 'isSeller' => $isSeller])
            </div>
        @endif

        {{-- ───────── What I owe, and what closes soon ───────── --}}
        @php $closing = $watchedSoon->isNotEmpty() || $endingSoon->isNotEmpty(); @endphp
        @if ($salesToPay->isNotEmpty() || $closing)
            <div class="dc-lower {{ $salesToPay->isNotEmpty() && $closing ? '' : 'dc-lower--one' }}">
                @if ($salesToPay->isNotEmpty())
                    <x-ds.section title="Won and to pay" id="to-pay" class="dc-sec" description="Lots you won that are not paid for yet.">
                        <x-slot name="actions"><x-ds.button :href="auction_route('buyer.won')" variant="link" size="sm">All the lots I have won</x-ds.button></x-slot>
                        <table class="dc-tbl dc-tbl--pay">
                            <caption class="sr-only">Won lots waiting for payment</caption>
                            <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Amount</th><th scope="col">Deadline</th><th scope="col" class="act"><span class="sr-only">Action</span></th></tr></thead>
                            <tbody>
                                @foreach ($salesToPay->take(6) as $position)
                                    @php $sale = $position->settlement; @endphp
                                    <tr style="--i: {{ $loop->index }}">
                                        <td class="dc-lotcell">
                                            <a class="dc-lotname" href="{{ route('settlements.show', $sale) }}">{{ $sale->auction->title }}</a>
                                            @if ($position->isOverdue())<span class="dc-sub"><x-ds.status state="payment_overdue" size="sm" /></span>@endif
                                        </td>
                                        <td data-label="Amount" class="num"><x-ds.money :amount="$sale->amount_cents / 100" /></td>
                                        <td data-label="Deadline" class="dc-deadline">{{ $position->canPayNow() ? ($sale->payment_due_at ? Display::time($sale->payment_due_at, 'D j M, H:i') : 'Not set') : 'Opens soon' }}</td>
                                        <td class="act"><x-ds.button :href="route('settlements.show', $sale)" size="sm" :variant="$position->canPayNow() ? 'primary' : 'quiet'">{{ $position->winnerAction() ?? 'View sale' }}</x-ds.button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ds.section>
                @endif

                @if ($closing)
                    <div class="dc-closing-col">
                        @if ($watchedSoon->isNotEmpty())
                            <x-ds.section title="Watching, closing in the next 24 hours" id="watching" class="dc-sec">
                                <x-slot name="actions"><x-ds.button :href="auction_route('buyer.watchlist')" variant="link" size="sm">All {{ $watchingCount }} watched {{ $watchPlural($watchingCount) }}</x-ds.button></x-slot>
                                @include('dashboard._catalogue', ['lots' => $watchedSoon, 'userId' => auth()->id()])
                            </x-ds.section>
                        @endif
                        @if ($endingSoon->isNotEmpty())
                            <x-ds.section title="Closing in the next 24 hours" id="closing" class="dc-sec">
                                <x-slot name="actions"><x-ds.button :href="route('auctions.index', ['state' => 'live'])" variant="link" size="sm">All live lots</x-ds.button></x-slot>
                                @include('dashboard._catalogue', ['lots' => $endingSoon, 'userId' => auth()->id()])
                            </x-ds.section>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ───────── The foot: live counts only, zero parts are not printed ───────── --}}
        <footer class="dc-foot">
            <dl>
                @if ($seller && $seller['live'])<div><dt>Open for bids</dt><dd>{{ $seller['live'] }}</dd></div>@endif
                @if ($seller && $seller['closing_soon'])<div><dt>Closing within 24 hours</dt><dd>{{ $seller['closing_soon'] }}</dd></div>@endif
                @if ($seller && $seller['pending'])<div><dt>Awaiting your decision</dt><dd><a href="#decisions">{{ $seller['pending'] }}</a></dd></div>@endif
                @if ($seller && $seller['scheduled'])<div><dt>Scheduled</dt><dd>{{ $seller['scheduled'] }}</dd></div>@endif
                @if ($seller && $seller['drafts'])<div><dt>Drafts, not published</dt><dd>{{ $seller['drafts'] }}</dd></div>@endif
                @if ($salesToPay->isNotEmpty())<div><dt>Won lots to pay</dt><dd>{{ $salesToPay->count() }}</dd></div>@endif
                @if (! $isSeller && (int) $landing['open'] > 0)<div><dt>Lots open for bids on the floor</dt><dd>{{ (int) $landing['open'] }}</dd></div>@endif
            </dl>
            <p>Times shown in {{ $zone }}</p>
        </footer>
    @endif
</x-app-layout>
