{{--
  The destinations of the signed-in shell, defined once. Variables in: $variant ('rail' | 'drawer').
  Buyer: Browse, My bids, Watching, Won. Seller: My lots, Payments. Operator: Payment operations.
  Targets that do not exist yet go through auction_route(): buyer.bids, buyer.watchlist, buyer.won (a '#' link that does nothing until the route is registered).
--}}
@php
    $variant = $variant ?? 'rail';
    $isOperator = (bool) auth()->user()?->isPlatformOperator();
@endphp
@if ($variant === 'rail')
    <x-ds.nav-item :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Home</x-ds.nav-item>
    <p class="sh-group" id="nav-buying">Buying</p>
    <x-ds.nav-item :href="route('auctions.index')" icon="browse" :active="request()->routeIs('auctions.*')">Browse lots</x-ds.nav-item>
    <x-ds.nav-item :href="auction_route('buyer.bids')" icon="bids" :active="request()->routeIs('buyer.bids')">My bids</x-ds.nav-item>
    <x-ds.nav-item :href="auction_route('buyer.watchlist')" icon="bookmark" :active="request()->routeIs('buyer.watchlist')">Watching</x-ds.nav-item>
    <x-ds.nav-item :href="auction_route('buyer.won')" icon="trophy" :active="request()->routeIs('buyer.won')">Won</x-ds.nav-item>
    <p class="sh-group" id="nav-selling">Selling</p>
    <x-ds.nav-item :href="route('seller.auctions.index')" icon="tag" :active="request()->routeIs('seller.auctions.*')">My lots</x-ds.nav-item>
    <x-ds.nav-item :href="route('settlements.index')" icon="receipt" :active="request()->routeIs('settlements.*')">Payments</x-ds.nav-item>
    @if ($isOperator)
        <p class="sh-group" id="nav-ops">Operations</p>
        <x-ds.nav-item :href="route('operator.settlement-payments.index')" icon="shield" :active="request()->routeIs('operator.*')">Payment operations</x-ds.nav-item>
    @endif
@elseif ($variant === 'drawer')
    {{-- The shared drawer's navigation (phones and small tablets): the same destinations as the rail, in the same groups, using dot-ui's drawer classes. --}}
    @php
        $link = fn (string $label, string $href, bool $current) => '<li><a class="dh-dnav__link" href="'.e($href).'"'.($current ? ' aria-current="page"' : '').'><span class="dh-menu__label">'.e($label).'</span></a></li>';
        $groups = [
            ['Buying', 'drawer-buying', [
                ['Home', route('dashboard'), request()->routeIs('dashboard')],
                ['Browse lots', route('auctions.index'), request()->routeIs('auctions.*')],
                ['My bids', auction_route('buyer.bids'), request()->routeIs('buyer.bids')],
                ['Watching', auction_route('buyer.watchlist'), request()->routeIs('buyer.watchlist')],
                ['Won', auction_route('buyer.won'), request()->routeIs('buyer.won')],
            ]],
            ['Selling', 'drawer-selling', [
                ['List a lot', route('seller.auctions.create'), request()->routeIs('seller.auctions.create')],
                ['My lots', route('seller.auctions.index'), request()->routeIs('seller.auctions.*') && ! request()->routeIs('seller.auctions.create')],
                ['Payments', route('settlements.index'), request()->routeIs('settlements.*')],
            ]],
        ];
        if ($isOperator) {
            $groups[] = ['Operations', 'drawer-ops', [['Payment operations', route('operator.settlement-payments.index'), request()->routeIs('operator.*')]]];
        }
    @endphp
    <nav class="dh-dnav" aria-label="Dot.Auction">
        <ul>
            @foreach ($groups as [$label, $gid, $items])
                <li class="dh-dgroup">
                    <button type="button" class="dh-dgroup__btn" data-dh-disclosure aria-expanded="true" aria-controls="{{ $gid }}"><span class="dh-menu__label">{{ $label }}</span><span class="dh-chev" aria-hidden="true"></span></button>
                    <ul id="{{ $gid }}" class="dh-dgroup__list">
                        @foreach ($items as [$itemLabel, $href, $current]){!! $link($itemLabel, $href, $current) !!}@endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
