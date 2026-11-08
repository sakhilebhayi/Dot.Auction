{{--
  Watching. The lots the person follows, open lots first and the one closing soonest at the top; closed ones follow with their outcome.
  Each card has a real "Stop watching" control (a form, so it works without JavaScript).
--}}
@php
    use App\Support\Display;
    use App\Support\ViewerCue;
@endphp
<x-app-layout title="Watching" description="Lots you follow, the ones closing soonest first." width="wide">
    <x-slot name="header">Watching</x-slot>
    <x-slot name="actions"><x-ds.button :href="route('auctions.index', ['state' => 'live'])" variant="quiet" icon="browse">Find lots to watch</x-ds.button></x-slot>

    @if (session('status'))
        <x-ds.alert tone="success" class="dc-gap">{{ session('status') }}</x-ds.alert>
    @endif

    @if ($lots->isEmpty())
        <x-ds.empty-state :level="2" title="You are not watching any lots" icon="bookmark" body="Press Watch on a lot and it shows here, with the lots closing soonest first.">
            <x-ds.button :href="route('auctions.index', ['state' => 'live'])" icon="browse">Browse live lots</x-ds.button>
        </x-ds.empty-state>
    @else
        <p class="dc-resultbar__count"><strong>{{ number_format($lots->total()) }}</strong> {{ $lots->total() === 1 ? 'lot' : 'lots' }} watched</p>
        <h2 class="sr-only">Watched lots</h2>
        <ul class="ds-catalogue dc-gap-top" aria-label="Watched lots">
            @foreach ($lots as $lot)
                @php
                    $cue = ViewerCue::for($lot, auth()->id(), Display::lotState($lot));
                @endphp
                <li>
                    <x-ds.lot-card :auction="$lot" :viewer="$cue">
                        <x-slot name="actions">
                            <form method="POST" action="{{ route('buyer.watchlist.destroy', $lot) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ds-lot__watch is-on"><x-ds.icon name="bookmark" :size="16" filled /><span>Stop watching</span><span class="sr-only"> {{ $lot->title }}</span></button>
                            </form>
                        </x-slot>
                    </x-ds.lot-card>
                </li>
            @endforeach
        </ul>
        <x-ds.pagination :paginator="$lots" noun="watched lots" />
    @endif
</x-app-layout>
