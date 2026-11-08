{{--
  Browse. State (live, closing soon, scheduled, awaiting decision, sold, not sold, cancelled), category, search, sort and page all live in the URL.
  Every control changes the list; every chip is a link. See App\Services\Auctions\LotBrowser for what each state means.
--}}
@php
    use App\Services\Auctions\LotBrowser;
    use App\Support\Display;
    use App\Support\ViewerCue;

    $base = array_filter([
        'q' => $search !== '' ? $search : null,
        'category' => $category,
        'state' => $state,
        'sort' => $sort !== LotBrowser::DEFAULT_SORT ? $sort : null,
    ], fn ($v) => $v !== null);
    $link = fn (array $change = []) => route('auctions.index', array_filter(array_merge($base, $change), fn ($v) => $v !== null && $v !== ''));
    $filtered = $search !== '' || $category !== null || $state !== null;
    $promo = $closingSoon->isNotEmpty() && $auctions->currentPage() === 1;

    $tabs = [['label' => 'All lots', 'href' => $link(['state' => null]), 'current' => $state === null, 'count' => $counts['all']]];
    foreach (LotBrowser::STATES as $key => $word) {
        $tabs[] = ['label' => $word, 'href' => $link(['state' => $key]), 'current' => $state === $key, 'count' => $counts[$key]];
    }

    $empty = [
        'live' => ['No lots are live right now', 'Lots open for bidding show here. Check what is scheduled to open next.'],
        'ending' => ['Nothing is closing soon', 'Lots inside their final hour show here.'],
        'scheduled' => ['No lots are scheduled', 'Lots a seller has published but not opened yet show here.'],
        'pending' => ['No lots are waiting on a seller', 'Lots that ended below their reserve show here until the seller decides.'],
        'sold' => ['No lots have sold yet', 'Lots that closed with a sale show here.'],
        'unsold' => ['No lots ended unsold', 'Lots that closed without a sale show here.'],
        'cancelled' => ['No lots were cancelled', 'Lots a seller cancelled show here.'],
    ];
@endphp
<x-app-layout title="Browse lots" description="Live lots first, the ones closing soonest at the top." width="wide">
    <x-slot name="header">Browse lots</x-slot>

    {{-- One search. From 64rem it is the shell's search box (the top bar keeps the tab, category and sort); below that the top bar has none, so the page shows its own.
         Sort applies as soon as it changes; without JavaScript the Apply button is there. --}}
    <x-slot name="actions">
        <form method="GET" action="{{ route('auctions.index') }}" class="dc-filters" role="search" aria-label="Search and sort lots">
            @if ($category !== null)<input type="hidden" name="category" value="{{ $category }}">@endif
            @if ($state !== null)<input type="hidden" name="state" value="{{ $state }}">@endif
            <x-ds.input class="dc-filters__q" label="Search lots" name="q" id="browse-q" type="search" :value="$search" placeholder="Guitar, desk, camera" autocomplete="off" maxlength="100" />
            <x-ds.button type="submit" icon="search" variant="quiet" class="dc-filters__go">Search</x-ds.button>
            <x-ds.select class="dc-filters__sort" label="Sort by" name="sort" id="browse-sort" :value="$sort" :options="LotBrowser::SORTS" data-autosubmit />
            <noscript><x-ds.button type="submit" variant="quiet">Apply sort</x-ds.button></noscript>
        </form>
    </x-slot>

    @if ($promo)
        <section class="dc-closing" aria-labelledby="closing-soon-title">
            <div class="dc-closing__head">
                <h2 class="dc-closing__title" id="closing-soon-title">Closing soon</h2>
                <p class="dc-closing__sub">{{ $closingSoon->count() === 1 ? 'This lot closes' : 'These lots close' }} within the hour.</p>
            </div>
            <ol class="dc-closing__list">
                @foreach ($closingSoon as $lot)
                    @php $cue = ViewerCue::for($lot, $userId, 'ending'); @endphp
                    <x-ds.lot-row :auction="$lot" :viewer="$cue">
                        @if ($cue !== 'own')
                            <x-slot name="actions"><livewire:buyer.watch-toggle :key="'close-watch-'.$lot->id" :auction-id="$lot->id" :title="$lot->title" :watching="(bool) $lot->viewer_watching" /></x-slot>
                        @endif
                    </x-ds.lot-row>
                @endforeach
            </ol>
        </section>
    @endif

    <x-ds.tabs :tabs="$tabs" label="Lot state" class="dc-tabs" />

    @if ($categories->isNotEmpty() || $activeCategory)
        <nav class="dc-chips" aria-label="Category">
            <x-ds.chip :href="$link(['category' => null])" :current="$category === null">All categories</x-ds.chip>
            @foreach ($categories as $cat)
                <x-ds.chip :href="$link(['category' => $cat->id])" :current="$category === $cat->id" :count="$cat->lots">{{ $cat->name }}</x-ds.chip>
            @endforeach
        </nav>
    @endif

    <div class="dc-resultbar">
        <p class="dc-resultbar__count" role="status">
            <strong>{{ number_format($tally['all']) }}</strong> {{ $tally['all'] === 1 ? 'lot' : 'lots' }}
            @if ($state !== null) {{ ' ' }}{{ strtolower(LotBrowser::STATES[$state]) }}@endif
            @if ($search !== '') {{ ' ' }}matching "{{ $search }}"@endif<!--
            -->@if ($tally['closing'] > 0 && $tally['others'] > 0)<span class="dc-resultbar__split">: {{ number_format($tally['closing']) }} closing within the hour and {{ number_format($tally['others']) }} {{ $tally['others'] === 1 ? 'other' : 'others' }}</span>@endif
        </p>
        @if ($filtered)
            <ul class="dc-active" aria-label="Active filters">
                @if ($search !== '')<li><x-ds.chip :href="$link(['q' => null])"><span class="sr-only">Remove search: </span>"{{ $search }}"<x-ds.icon name="close" :size="14" /></x-ds.chip></li>@endif
                @if ($activeCategory)<li><x-ds.chip :href="$link(['category' => null])"><span class="sr-only">Remove category: </span>{{ $activeCategory->name }}<x-ds.icon name="close" :size="14" /></x-ds.chip></li>@endif
                @if ($state !== null)<li><x-ds.chip :href="$link(['state' => null])"><span class="sr-only">Remove filter: </span>{{ LotBrowser::STATES[$state] }}<x-ds.icon name="close" :size="14" /></x-ds.chip></li>@endif
                <li><a class="ds-btn ds-btn--link ds-btn--sm" href="{{ route('auctions.index', array_filter(['sort' => $base['sort'] ?? null])) }}"><span class="ds-btn__label">Clear all</span></a></li>
            </ul>
        @endif
    </div>

    @if ($auctions->isEmpty() && $closingSoon->isEmpty())
        @if ($search !== '' || $category !== null)
            <x-ds.empty-state :level="2" title="No lots match" icon="search" body="Try a shorter search, another category or another tab.">
                <x-ds.button :href="route('auctions.index', array_filter(['sort' => $base['sort'] ?? null]))" variant="quiet">Clear all filters</x-ds.button>
            </x-ds.empty-state>
        @elseif ($state !== null)
            <x-ds.empty-state :level="2" :title="$empty[$state][0]" icon="gavel" :body="$empty[$state][1]">
                <x-ds.button :href="$link(['state' => $state === 'live' && $counts['scheduled'] > 0 ? 'scheduled' : null])" variant="quiet">{{ $state === 'live' && $counts['scheduled'] > 0 ? 'See scheduled lots' : 'Show all lots' }}</x-ds.button>
            </x-ds.empty-state>
        @else
            <x-ds.empty-state :level="2" title="No lots yet" icon="gavel" body="Nothing has been published. Lots show here as soon as a seller publishes one.">
                <x-ds.button :href="route('seller.auctions.create')" icon="plus">List a lot</x-ds.button>
            </x-ds.empty-state>
        @endif
    @elseif ($auctions->isNotEmpty())
        <h2 class="{{ $promo ? 'dc-closing__title dc-closing__title--rest' : 'sr-only' }}">{{ $promo ? 'Other lots' : 'Lots' }}</h2>
        <ul class="ds-catalogue" aria-label="Lots">
            @foreach ($auctions as $auction)
                @php
                    $lotState = Display::lotState($auction);
                    $cue = ViewerCue::for($auction, $userId, $lotState);
                    $watching = (bool) $auction->viewer_watching;
                    $canWatch = $cue !== 'own' && in_array($lotState, ['live', 'ending', 'scheduled'], true);
                @endphp
                <li>
                    <x-ds.lot-card :auction="$auction" :viewer="$cue">
                        @if ($canWatch)
                            <x-slot name="actions">
                                <livewire:buyer.watch-toggle :key="'watch-'.$auction->id" :auction-id="$auction->id" :title="$auction->title" :watching="$watching" />
                            </x-slot>
                        @endif
                    </x-ds.lot-card>
                </li>
            @endforeach
        </ul>

        <x-ds.pagination :paginator="$auctions" :noun="$closingSoon->isNotEmpty() ? 'other lots' : 'lots'" />
    @endif
</x-app-layout>
