{{-- Lots closing soon as ruled strips with the clock as the headline, the viewer's cue and the watch control. In: $lots (loaded with LotBrowser::withCardData), $userId. --}}
<ol class="dc-closing__list" aria-label="Lots">
    @foreach ($lots as $lot)
        @php $cue = \App\Support\ViewerCue::for($lot, $userId, \App\Support\Display::lotState($lot)); @endphp
        <x-ds.lot-row :auction="$lot" :viewer="$cue" size="lg">
            @if ($cue !== 'own')
                <x-slot name="actions"><livewire:buyer.watch-toggle :key="'dash-watch-'.$lot->id" :auction-id="$lot->id" :title="$lot->title" :watching="(bool) $lot->viewer_watching" /></x-slot>
            @endif
        </x-ds.lot-row>
    @endforeach
</ol>
