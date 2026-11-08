@php
    use App\Support\Display;
    $chips = [['All', null, $counts['all']], ['Buying', 'buying', $counts['buying']], ['Selling', 'selling', $counts['selling']]];
@endphp
<x-app-layout title="Payments" description="Lots you won and lots you sold, and what each one needs from you.">
    <x-slot name="header">Payments</x-slot>

    @if ($toPay > 0)
        <x-ds.alert tone="notice" :title="$toPay === 1 ? '1 payment to make' : $toPay.' payments to make'" class="sl-gap">
            Pay from the row with the Pay now button. You are taken to Paystack, and Dot.Auction never sees your card details.
        </x-ds.alert>
    @endif

    @if ($counts['all'] === 0)
        <x-ds.empty-state title="No payments yet" icon="receipt" body="When you win a lot, or one of yours sells, it appears here with what to do next.">
            <x-ds.button :href="route('auctions.index')" icon="browse">Browse lots</x-ds.button>
        </x-ds.empty-state>
    @else
        @if ($counts['buying'] > 0 && $counts['selling'] > 0)
            <nav class="pay-chips" aria-label="Show payments for">
                @foreach ($chips as [$label, $key, $n])
                    <x-ds.chip :href="route('settlements.index', $key ? ['role' => $key] : [])" :current="$role === $key" :count="$n">{{ $label }}</x-ds.chip>
                @endforeach
            </nav>
        @endif

        <x-ds.table caption="Your payments" class="pay-table sl-cards">
            <thead>
                <tr>
                    <th scope="col">Lot</th>
                    <th scope="col">You</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="sr-only">Next step</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($settlements as $row)
                    @php
                        $won = $row->winner_id === auth()->id();
                        $canPay = $won && $row->isPayable() && $payments->canTakePayments();
                        $title = $row->auction->title ?? 'Lot #'.$row->auction_id;
                    @endphp
                    <tr>
                        <td class="pay-lot"><a href="{{ route('settlements.show', $row) }}">{{ $title }}</a></td>
                        <td data-label="You">{{ $won ? 'You won' : 'You sold' }}</td>
                        <td data-label="Amount" class="num"><x-ds.money :amount="$row->amount_cents / 100" /></td>
                        <td data-label="Status" class="sl-span">
                            <x-ds.status :state="$row->status" size="sm" />
                            @if ($row->isPayable() && $row->payment_due_at && $payments->canTakePayments())
                                <span class="sl-sub">{{ $row->status === 'payment_overdue' ? 'Was due' : ($won ? 'Pay by' : 'Buyer pays by') }} {{ Display::time($row->payment_due_at, 'j M, H:i') }}</span>
                            @elseif ($row->isPaid() && $row->paid_at)
                                <span class="sl-sub">Paid {{ Display::time($row->paid_at, 'j M, H:i') }}</span>
                            @endif
                        </td>
                        <td class="actions pay-actions">
                            @if ($canPay)
                                <form method="POST" action="{{ route('settlements.pay', $row) }}">
                                    @csrf
                                    <x-ds.button type="submit" size="sm">Pay now<span class="sr-only"> for {{ $title }}</span></x-ds.button>
                                </form>
                            @else
                                <x-ds.button :href="route('settlements.show', $row)" variant="quiet" size="sm">View<span class="sr-only"> payment for {{ $title }}</span></x-ds.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ds.table>

        @if ($counts['selling'] > 0)
            <p class="pay-foot">For lots you sold: winners pay Dot.Auction, and paying you out is a manual step for now. Open a sale to see where it stands.</p>
        @endif

        <x-ds.pagination :paginator="$settlements" noun="payments" />
    @endif
</x-app-layout>
