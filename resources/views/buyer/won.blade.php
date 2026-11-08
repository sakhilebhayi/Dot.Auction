{{--
  Won. Lots the signed-in person won (Buy Now purchases included), each with its sale and the way to pay.
  The sale is the record in auction_settlements; if it has not been written yet (it is recorded within minutes of the close) the row says so.
--}}
@php use App\Support\Display; @endphp
<x-app-layout title="Won lots" description="Lots you won, and what is left to pay." width="wide">
    <x-slot name="header">Won lots</x-slot>

    @if ($toPay > 0)
        <x-ds.alert :tone="$overdueCount > 0 ? 'danger' : 'notice'" class="dc-gap" title="{{ $toPay }} {{ $toPay === 1 ? 'sale waits' : 'sales wait' }} for payment">
            You owe {{ $owed }} in total.
            <a href="{{ route('settlements.index') }}">See what to pay</a>.
        </x-ds.alert>
    @endif
    @if ($toPayLater > 0)
        <x-ds.alert tone="info" class="dc-gap" title="Payment opens soon">
            Nothing is due today. You will owe {{ $owedLater }} across {{ $toPayLater }} won {{ $toPayLater === 1 ? 'lot' : 'lots' }} once payment opens, and we will tell you.
        </x-ds.alert>
    @endif

    @if ($lots->isEmpty())
        <x-ds.empty-state :level="2" title="You have not won a lot yet" icon="trophy" body="When you win a lot, it shows here with its price and what to pay.">
            <x-ds.button :href="route('auctions.index', ['state' => 'live'])" icon="browse">Browse live lots</x-ds.button>
        </x-ds.empty-state>
    @else
        <x-ds.table caption="Lots you won">
            <thead><tr><th scope="col">Lot</th><th scope="col" class="num">Price</th><th scope="col">Won</th><th scope="col">Payment</th><th scope="col" class="actions"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
                @foreach ($lots as $lot)
                    @php $sale = $lot->settlement; $position = $sale ? \App\Support\SettlementPosition::of($sale) : null; @endphp
                    <tr>
                        <td data-label="Lot" class="dc-lotcell"><a class="dc-lotname" href="{{ route('auctions.show', $lot) }}">{{ $lot->title }}</a></td>
                        <td data-label="Price" class="num"><x-ds.money :amount="$sale ? $sale->amount_cents / 100 : $lot->current_price" /></td>
                        <td data-label="Won">{{ Display::time($lot->ends_at, 'D j M, H:i') }}</td>
                        <td data-label="Payment">
                            @if ($sale)
                                <x-ds.status :state="$position->badge()" size="sm" />
                                @if ($position->showsDeadline())<span class="dc-sub">{{ $position->isOverdue() ? 'Was due' : 'Pay by' }} {{ Display::time($sale->payment_due_at, 'D j M, H:i') }}</span>
                                @elseif ($position->isWaiting())<span class="dc-sub">Payment opens soon</span>@endif
                                @if ($sale->isPaid() && $sale->paid_at)<span class="dc-sub">Paid {{ Display::time($sale->paid_at, 'D j M, H:i') }}</span>@endif
                            @else
                                <span class="dc-sub">The sale is being recorded.</span>
                            @endif
                        </td>
                        <td class="actions">
                            @if ($sale)<x-ds.button :href="route('settlements.show', $sale)" size="sm" :variant="$position->canPayNow() ? 'primary' : 'quiet'">{{ $position->winnerAction() ?? 'View sale' }}</x-ds.button>
                            @else<x-ds.button :href="route('auctions.show', $lot)" variant="quiet" size="sm">Open lot</x-ds.button>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ds.table>
        <x-ds.pagination :paginator="$lots" noun="won lots" />
    @endif
</x-app-layout>
