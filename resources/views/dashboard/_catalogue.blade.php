{{-- A short catalogue of lot cards with the viewer's cue and the watch control. In: $lots (loaded with LotBrowser::withCardData), $userId. --}}
<ul class="ds-catalogue" aria-label="Lots">
    @foreach ($lots as $lot)
        @php
            $lotState = \App\Support\Display::lotState($lot);
            $cue = \App\Support\ViewerCue::for($lot, $userId, $lotState);
            $watching = (bool) $lot->viewer_watching;
        @endphp
        <li>
            <x-ds.lot-card :auction="$lot" :viewer="$cue">
                @if ($cue !== 'own')
                    <x-slot name="actions">
                        <livewire:buyer.watch-toggle :key="'dash-watch-'.$lot->id" :auction-id="$lot->id" :title="$lot->title" :watching="$watching" />
                    </x-slot>
                @endif
            </x-ds.lot-card>
        </li>
    @endforeach
</ul>
