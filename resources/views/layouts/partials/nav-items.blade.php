{{--
  The destinations of the signed-in shell, defined once. Variables in: $variant ('rail' | 'row').
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
@else
    <x-ds.nav-item variant="row" :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Home</x-ds.nav-item>
    <x-ds.nav-item variant="row" :href="route('auctions.index')" icon="browse" :active="request()->routeIs('auctions.*')">Browse lots</x-ds.nav-item>
    <x-ds.nav-item variant="row" :href="auction_route('buyer.bids')" icon="bids" :active="request()->routeIs('buyer.bids')">My bids</x-ds.nav-item>
    <x-ds.nav-item variant="row" :href="auction_route('buyer.watchlist')" icon="bookmark" :active="request()->routeIs('buyer.watchlist')">Watching</x-ds.nav-item>
    <x-ds.nav-item variant="row" :href="auction_route('buyer.won')" icon="trophy" :active="request()->routeIs('buyer.won')">Won</x-ds.nav-item>
    <x-ds.nav-item variant="row" :href="route('seller.auctions.index')" icon="tag" :active="request()->routeIs('seller.auctions.*')">My lots</x-ds.nav-item>
    <x-ds.nav-item variant="row" :href="route('settlements.index')" icon="receipt" :active="request()->routeIs('settlements.*')">Payments</x-ds.nav-item>
    @if ($isOperator)
        <x-ds.nav-item variant="row" :href="route('operator.settlement-payments.index')" icon="shield" :active="request()->routeIs('operator.*')">Payment operations</x-ds.nav-item>
    @endif
@endif
