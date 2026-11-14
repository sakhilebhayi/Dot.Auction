{{--
  Home's landing block: what a person meets when they arrive from InfoDot. The real logo, a welcome by first name, what this product is for,
  ONE dominant action for the role the person's own rows give them (DashboardController::landing), and one real lot with its clock and price.
  In: $landing = [role: seller|buyer, firstName, company (null for a personal team), open (lots open for bids: the seller's own, or other people's),
                  lot (?Auction, loaded with LotBrowser data), seller (?counts), infodot (base URL)].
  The primary action carries data-primary-action and there is exactly one on the page. Every figure is a count or a row; with nothing open it says so.
--}}
@php
    use App\Support\Display;
    use App\Support\ViewerCue;

    $isSeller = $landing['role'] === 'seller';
    $lot = $landing['lot'];
    $open = (int) $landing['open'];
    $lots = fn (int $n) => $n === 1 ? 'lot' : 'lots';
    $for = $landing['company'] ? 'You are here for '.$landing['company'].', and ' : '';

    if ($isSeller) {
        $copy = $open > 0
            ? ucfirst($for.'you have '.$open.' '.$lots($open).' open for bids on Dot.Auction.').' List the next one: it stays a private draft until you publish it.'
            : ucfirst($for.'you have no lot open for bids on Dot.Auction right now.').' List one: it stays a private draft until you publish it.';
    } else {
        $copy = $open > 0
            ? ucfirst($for.$open.' '.$lots($open).' '.($open === 1 ? 'is' : 'are').' open for bids on Dot.Auction right now.').' Every lot shows its price, the next valid bid and when it closes.'
            : ucfirst($for.'no lot is open for bids on Dot.Auction right now.').' You can still look through scheduled and sold lots.';
    }

    $cue = $lot ? ViewerCue::for($lot, auth()->id(), Display::lotState($lot)) : null;
    $bids = $lot ? (int) ($lot->bids_count ?? 0) : 0;

    // What a seller with nothing open still has, from their own counts.
    $shelf = [];
    if ($isSeller && ! $lot && $landing['seller']) {
        foreach (['scheduled' => ['scheduled', 'scheduled'], 'pending' => ['awaiting your decision', 'awaiting your decision'], 'drafts' => ['draft not published', 'drafts not published']] as $key => [$one, $many]) {
            $n = (int) $landing['seller'][$key];
            if ($n > 0) {
                $shelf[] = $n.' '.($n === 1 ? $one : $many);
            }
        }
    }
@endphp
<header class="ld" data-landing="{{ $landing['role'] }}">
    <div class="ld__mast">
        <x-ds.brand :width="168" class="ld__logo" />
        <nav class="ld__back" aria-label="Dot ecosystem">
            <a href="{{ $landing['infodot'] }}/home">InfoDot</a>
            <a href="{{ $landing['infodot'] }}/platforms">Platforms</a>
        </nav>
    </div>

    <div class="ld__body">
        <div class="ld__lead">
            <h1 class="ld__title" id="page-title">Welcome to the sale room, {{ $landing['firstName'] }}</h1>
            <p class="ld__copy">{{ $copy }}</p>
            <div class="ld__actions">
                @if ($isSeller)
                    <x-ds.button :href="route('seller.auctions.create')" size="lg" icon="plus" data-primary-action>List a lot</x-ds.button>
                    <x-ds.button :href="route('auctions.index')" variant="link">Browse lots</x-ds.button>
                @else
                    <x-ds.button :href="route('auctions.index')" size="lg" icon="browse" data-primary-action>{{ $open > 0 ? 'Browse live lots' : 'Browse lots' }}</x-ds.button>
                    <x-ds.button :href="route('seller.auctions.create')" variant="link">List a lot</x-ds.button>
                @endif
            </div>
        </div>

        <section class="ld__lot" aria-labelledby="ld-lot-title">
            @if ($lot)
                <h2 class="ld__lot-title" id="ld-lot-title">{{ $isSeller ? 'Your next lot to close' : 'Closing next on the floor' }}</h2>
                <div class="ld__clock">
                    <x-ds.countdown :ends="$lot->ends_at" size="xl" label="Closes in" :show-at="false" stacked />
                    <x-ds.rail :starts="$lot->starts_at" :ends="$lot->ends_at" :extensions="(int) $lot->snipe_extensions" thick />
                </div>
                <div class="ld__lot-row">
                    <div class="ld__lot-what">
                        <a class="ld__lot-name" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a>
                        <p class="ld__lot-facts">
                            <span>Closes {{ Display::time($lot->ends_at, null, true) }}</span>
                            <span>{{ $bids }} {{ $bids === 1 ? 'bid' : 'bids' }}</span>
                            @if ($cue && $cue !== 'own')@php $c = ViewerCue::describe($cue); @endphp<span class="ds-lot__cue ds-lot__cue--{{ $cue }}"><x-ds.icon :name="$c['icon']" :size="14" /> {{ $c['word'] }}</span>@endif
                        </p>
                    </div>
                    <div class="ld__lot-price">
                        <span class="ld__lot-label">{{ $bids > 0 ? 'Current bid' : 'Starting bid' }}</span>
                        <x-ds.money :amount="$bids > 0 ? $lot->current_price : $lot->starting_price" size="xl" />
                    </div>
                </div>
            @elseif ($isSeller)
                <h2 class="ld__lot-title" id="ld-lot-title">None of your lots is open for bids</h2>
                <p class="ld__lot-empty">{{ $shelf ? 'You have '.implode(', ', $shelf).'.' : 'A lot shows here with its clock and its price once it is open.' }}</p>
                <x-ds.button :href="route('seller.auctions.index')" variant="quiet" size="sm">Manage my lots</x-ds.button>
            @else
                <h2 class="ld__lot-title" id="ld-lot-title">Nothing is live right now</h2>
                <p class="ld__lot-empty">No lot is open for bids. The next one to close shows here, with its clock and its price, as soon as a seller opens one.</p>
            @endif
        </section>
    </div>
</header>
