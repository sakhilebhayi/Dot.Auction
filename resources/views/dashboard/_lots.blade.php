{{-- My lots: the person's own lots, with the open ones in a table the stage can follow. In: $seller, $sellingNow, $selected, $selectedKind. --}}
@php use App\Support\Display; @endphp
<x-ds.section title="My lots" id="selling" class="dc-sec">
    <x-slot name="actions"><x-ds.button :href="route('seller.auctions.index')" variant="quiet" size="sm">Manage my lots</x-ds.button></x-slot>
    @if ($sellingNow->isNotEmpty())
        <table class="dc-tbl" data-sortable>
            <caption class="sr-only">Your lots that are open for bids</caption>
            <thead><tr><th scope="col" data-key="title">Lot</th><th scope="col" class="num" data-key="now">Price now</th><th scope="col" class="num" data-key="bids">Bids</th><th scope="col" data-key="closes">Closes</th></tr></thead>
            <tbody>
                @foreach ($sellingNow as $lot)
                    @php $lotState = Display::lotState($lot); $isSel = $selectedKind === 'selling' && $selected?->id === $lot->id; @endphp
                    <tr @if ($isSel) aria-current="true" @endif style="--i: {{ $loop->index }}"
                        data-title="{{ $lot->title }}" data-now="{{ $lot->current_price }}" data-bids="{{ (int) $lot->bids_count }}" data-closes="{{ $lot->ends_at->getTimestamp() }}">
                        <td class="dc-lotcell"><div class="dc-lotcell__in">
                            @include('dashboard._thumb', ['lot' => $lot])
                            <span class="dc-lotcell__txt">
                                <a class="dc-lotname" href="{{ route('dashboard', ['lot' => $lot->id]) }}#stage">{{ $lot->title }}</a>
                                <span class="dc-sub"><x-ds.status :state="$lotState" size="sm" />@if ((int) $lot->snipe_extensions > 0) <x-ds.status state="extended" size="sm" :count="(int) $lot->snipe_extensions" />@endif</span>
                            </span>
                        </div></td>
                        <td data-label="Price now" class="num"><x-ds.money :amount="$lot->current_price" /></td>
                        <td data-label="Bids" class="num">{{ (int) $lot->bids_count }}</td>
                        <td data-label="Closes"><x-ds.countdown :ends="$lot->ends_at" size="sm" label="" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="dc-calm">Nothing is open for bids. Your other lots are counted at the foot of the page.</p>
    @endif
</x-ds.section>
