@php
    use App\Models\Auction;
    use App\Support\Display;
    use App\Support\SettlementPosition;
    $tabs = [['label' => 'All', 'href' => route('operator.settlement-payments.index'), 'current' => $status === null, 'count' => $allCount]];
    $words = ['unmatched' => 'Needs a person', 'initiated' => 'Attempts started', 'paid' => 'Paid', 'failed' => 'Attempts failed', 'expired' => 'Attempts expired'];
    foreach ($filters as $filter) {
        $n = (int) ($counts[$filter] ?? 0);
        if ($n > 0 || $status === $filter) {
            $tabs[] = ['label' => $words[$filter], 'href' => route('operator.settlement-payments.index', ['status' => $filter]), 'current' => $status === $filter, 'count' => $n];
        }
    }
@endphp
<x-app-layout title="Payment operations" description="Every Paystack payment attempt, and every payment event that matched no sale. Read-only.">
    <x-slot name="header">Payment operations</x-slot>

    @if ($config->isMisconfigured())
        <x-ds.alert tone="danger" title="Payments are switched off" class="sl-gap">{{ $config->problem() }}</x-ds.alert>
    @elseif (! $config->isConfigured())
        <x-ds.alert tone="info" title="Online payment is not switched on" class="sl-gap">No Paystack key is set, so winners cannot pay.</x-ds.alert>
    @endif

    @if ($attention > 0)
        <x-ds.alert tone="danger" :title="$attention === 1 ? '1 payment needs a person' : $attention.' payments need a person'" class="sl-gap">
            Money was reported that could not be applied to a sale. Nothing was marked paid. Decide in Paystack's dashboard whether to refund or reconcile by hand.
            @unless ($attentionOnly)<a class="sl-link" href="{{ route('operator.settlement-payments.index', ['status' => 'unmatched']) }}">Show only these</a>@endunless
        </x-ds.alert>
    @else
        <x-ds.alert tone="success" class="sl-gap" role="status">No payments need a person.</x-ds.alert>
    @endif

    @if ($allCount > 0 && ! $config->canTakePayments())
        <x-ds.alert tone="info" class="sl-gap" title="These rows are earlier records">While payment is switched off no new attempt can start, and winners owe nothing today.</x-ds.alert>
    @endif

    @if ($allCount === 0)
        <x-ds.empty-state title="No payments have been recorded yet" icon="receipt" body="Every checkout a winner starts, and every payment event Paystack sends, will be listed here." />
    @else
        <x-ds.tabs :tabs="$tabs" label="Filter payments by status" />

        @if ($payments->isEmpty())
            <x-ds.empty-state class="sl-gap-top" :title="$attentionOnly ? 'Nothing needs attention' : 'No payments with this status'" icon="check-circle">
                <x-ds.button :href="route('operator.settlement-payments.index')" variant="quiet">Show all payments</x-ds.button>
            </x-ds.empty-state>
        @else
            <div class="ops-region" role="region" tabindex="0" aria-label="Payments, scrolls sideways when the table is wider than the screen">
                <x-ds.table caption="Payments" class="ops-table sl-cards">
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Amount</th>
                            <th scope="col">Sale</th>
                            <th scope="col">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr @class(['ops-row--attention' => $payment->status === 'unmatched'])>
                                <td data-label="Reference" class="ops-ref">{{ $payment->reference }}</td>
                                <td data-label="Status" class="sl-span">
                                    <x-ds.status :state="$payment->status" size="sm" />
                                    @if ($payment->reason)<span class="ops-reason">{{ $payment->reasonLabel() }}</span>@endif
                                    @if (in_array($payment->status, ['initiated', 'failed', 'expired'], true))<span class="sl-sub">Payment attempt, not completed</span>@endif
                                    @if ($payment->summary)<span class="sl-sub">{{ $payment->summary }}</span>@endif
                                </td>
                                <td data-label="Amount" class="num">
                                    @if ($payment->amount_cents === null)<span class="sl-sub">None</span>@else<x-ds.money :amount="$payment->amount_cents / 100" />@endif
                                </td>
                                <td data-label="Sale" class="sl-span">
                                    @if ($payment->settlement)
                                        {{ $payment->settlement->auction->title ?? 'Lot #'.$payment->settlement->auction_id }}
                                        <span class="sl-sub">Sale: {{ strtolower(SettlementPosition::of($payment->settlement, $config)->listLabel(null)) }}</span>
                                    @else
                                        <span class="sl-sub">Matches no sale</span>
                                    @endif
                                </td>
                                <td data-label="Recorded">{{ Display::time($payment->created_at, 'j M Y, H:i', true) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ds.table>
            </div>
            <x-ds.pagination :paginator="$payments" noun="payments" />
        @endif
    @endif
</x-app-layout>
