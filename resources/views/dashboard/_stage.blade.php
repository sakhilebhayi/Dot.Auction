{{--
  The stage: the selected lot beside the tables, a flat panel with its photo, the gold current-price rung, the clock and the rail (both the real ds components,
  ticked by resources/js/ds.js), and its facts. In: $lot (?Auction; images loaded), $kind (bid | selling | floor), $mine (bool, the viewer's own lot), $isSeller.
  Nothing here is invented: a figure is a stored value, and a lot with no photo says so.
--}}
@php
    use App\Support\Display;
    use App\Support\ViewerCue;

    $state = $lot ? Display::lotState($lot) : null;
    $live = in_array($state, ['live', 'ending'], true);
    $bids = $lot ? (int) ($lot->bids_count ?? 0) : 0;
    $hasBids = $bids > 0;
    $ext = $lot ? (int) $lot->snipe_extensions : 0;
    $photo = $lot?->images->first();
    $cue = $lot && $kind !== 'selling' ? ViewerCue::for($lot, auth()->id(), $state) : null;
    $title = match (true) {
        $lot === null => $isSeller ? 'None of your lots is open for bids' : 'Nothing is live right now',
        $kind === 'floor' => $isSeller ? 'Your next lot to close' : 'Closing next on the floor',
        default => $lot->title,
    };
    $shelf = [];
    if ($lot === null && $isSeller && ! empty($landing['seller'])) {
        foreach (['scheduled' => ['scheduled', 'scheduled'], 'pending' => ['awaiting your decision', 'awaiting your decision'], 'drafts' => ['draft not published', 'drafts not published']] as $key => [$one, $many]) {
            $n = (int) $landing['seller'][$key];
            if ($n > 0) { $shelf[] = $n.' '.($n === 1 ? $one : $many); }
        }
    }
@endphp
<aside class="stage" id="stage" aria-labelledby="stage-h" data-stage="{{ $kind ?? 'empty' }}">
    @if ($lot)
        <div class="stage__media">
            @if ($photo)
                <img src="{{ $lot->imageUrl($photo, 'medium') }}" srcset="{{ $lot->imageUrl($photo, 'thumb') }} 480w, {{ $lot->imageUrl($photo, 'medium') }} 1200w" sizes="(min-width: 64rem) 30rem, 100vw" alt="Photo of {{ $lot->title }}" width="640" height="480" decoding="async">
            @else
                <span class="stage__nophoto"><x-ds.icon name="image" :size="24" /> No photo yet</span>
            @endif
        </div>
        <div class="stage__body">
            <h2 class="stage__title" id="stage-h">{{ $title }}</h2>
            @if ($kind === 'floor')
                <p class="stage__lot"><a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a></p>
            @endif
            <div class="stage__who">
                <x-ds.status :state="$state" size="sm" />
                @if ($live && $ext > 0)<x-ds.status state="extended" size="sm" :count="$ext" />@endif
                @if ($cue && $cue !== 'own')@php $c = ViewerCue::describe($cue); @endphp<span class="ds-lot__cue ds-lot__cue--{{ $cue }}"><x-ds.icon :name="$c['icon']" :size="14" /> {{ $c['word'] }}</span>@endif
            </div>

            <div class="rung">
                <span class="rung__label">{{ $hasBids ? 'Current bid' : 'Starting bid' }}</span>
                <x-ds.money :amount="$hasBids ? $lot->current_price : $lot->starting_price" size="money" />
            </div>

            @if ($live)
                <div class="stage__clock">
                    <x-ds.countdown :ends="$lot->ends_at" size="lg" label="Closes in" :show-at="false" stacked />
                    <x-ds.rail :starts="$lot->starts_at" :ends="$lot->ends_at" :extensions="$ext" thick />
                </div>
            @endif

            <x-ds.dl class="stage__facts">
                @if ($kind === 'bid' && $lot->viewer_top_bid !== null)
                    <div class="ds-dl__row"><dt>Your top bid</dt><dd><x-ds.money :amount="$lot->viewer_top_bid" /></dd></div>
                @endif
                <div class="ds-dl__row"><dt>Bids</dt><dd>{{ $bids }}</dd></div>
                <div class="ds-dl__row"><dt>{{ $live ? 'Closes' : 'Closed' }}</dt><dd>{{ Display::time($lot->ends_at, null, true) }}</dd></div>
            </x-ds.dl>

            <div class="stage__act">
                <x-ds.button :href="route('auctions.show', $lot)" variant="quiet">Open lot</x-ds.button>
            </div>
        </div>
    @else
        <div class="stage__media stage__media--empty">
            <svg class="stage__art" width="144" height="56" viewBox="0 0 144 56" aria-hidden="true" focusable="false">
                <rect class="arrive__rung" x="0" y="48" width="40" height="8"/><rect class="arrive__rung" x="0" y="36" width="64" height="8"/>
                <rect class="arrive__rung" x="0" y="24" width="88" height="8"/><rect class="arrive__rung" x="0" y="12" width="112" height="8"/>
                <rect class="arrive__rung arrive__rung--top" x="0" y="0" width="144" height="8"/>
            </svg>
        </div>
        <div class="stage__body">
            <h2 class="stage__title" id="stage-h">{{ $title }}</h2>
            @if ($isSeller)
                <p class="stage__empty">{{ $shelf ? 'You have '.implode(', ', $shelf).'.' : 'A lot shows here with its clock and its price once it is open.' }}</p>
                <div class="stage__act"><x-ds.button :href="route('seller.auctions.index')" variant="quiet" size="sm">Manage my lots</x-ds.button></div>
            @else
                <p class="stage__empty">No lot is open for bids. The next one to close shows here, with its clock and its price, as soon as a seller opens one.</p>
            @endif
        </div>
    @endif
</aside>
