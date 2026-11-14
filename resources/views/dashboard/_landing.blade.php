{{--
  Home's arrival band: one sentence about where the person stands, what this product is for, ONE dominant action for the role the person's own rows give them
  (DashboardController::landing), and the bid-ladder drawing. The lot with its clock and price is the stage beside the tables (dashboard._stage).
  In: $landing = [role: seller|buyer, firstName, company (null for a personal team), open (lots open for bids: the seller's own, or other people's),
                  lot (?Auction), seller (?counts), infodot (base URL)], $summary (?string, the standing sentence).
  The primary action carries data-primary-action and there is exactly one on the page.
  The headline states the current state; the logo lives in the rail and the way back to InfoDot in the shared platform switcher, so neither is repeated here.
--}}
@php
    $isSeller = $landing['role'] === 'seller';
    $open = (int) $landing['open'];
    $lots = fn (int $n) => $n === 1 ? 'lot' : 'lots';
    $for = $landing['company'] ? 'You are here for '.$landing['company'].', and ' : '';

    if ($isSeller) {
        $copy = $open > 0
            ? ucfirst($for.'you have '.$open.' '.$lots($open).' open for bids on Dot.Auction.').' List the next one: it stays a private draft until you publish it.'
            : ucfirst($for.'you have no lot open for bids on Dot.Auction right now.').' List one: it stays a private draft until you publish it.';
        $fallback = 'None of your lots is open for bids.';
    } else {
        $copy = $open > 0
            ? ucfirst($for.$open.' '.$lots($open).' '.($open === 1 ? 'is' : 'are').' open for bids on Dot.Auction right now.').' Every lot shows its price, the next valid bid and when it closes.'
            : ucfirst($for.'no lot is open for bids on Dot.Auction right now.').' You can still look through scheduled and sold lots.';
        $fallback = $open > 0 ? $open.' '.$lots($open).' '.($open === 1 ? 'is' : 'are').' open for bids.' : 'No lot is open for bids right now.';
    }
@endphp
<header class="arrive" data-landing="{{ $landing['role'] }}">
    <div class="arrive__lead">
        <h1 class="arrive__title" id="page-title">{{ $summary ?? $fallback }}</h1>
        <p class="arrive__copy">{{ $copy }}</p>
    </div>
    {{-- The bid ladder: five rungs, the top one gold. Drawn from the product's own price rung, static, and it says nothing a figure could contradict. --}}
    <svg class="arrive__art" width="144" height="56" viewBox="0 0 144 56" aria-hidden="true" focusable="false">
        <rect class="arrive__rung" x="0" y="48" width="40" height="8"/>
        <rect class="arrive__rung" x="0" y="36" width="64" height="8"/>
        <rect class="arrive__rung" x="0" y="24" width="88" height="8"/>
        <rect class="arrive__rung" x="0" y="12" width="112" height="8"/>
        <rect class="arrive__rung arrive__rung--top" x="0" y="0" width="144" height="8"/>
    </svg>
    <div class="arrive__act">
        @if ($isSeller)
            <x-ds.button :href="route('seller.auctions.create')" size="lg" icon="plus" data-primary-action>List a lot</x-ds.button>
            <x-ds.button :href="route('auctions.index')" variant="link">Browse lots</x-ds.button>
        @else
            <x-ds.button :href="route('auctions.index')" size="lg" icon="browse" data-primary-action>{{ $open > 0 ? 'Browse live lots' : 'Browse lots' }}</x-ds.button>
            <x-ds.button :href="route('seller.auctions.create')" variant="link">List a lot</x-ds.button>
        @endif
    </div>
</header>
