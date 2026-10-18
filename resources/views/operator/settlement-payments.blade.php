<x-app-layout>
<div class="dot-page">

    <div class="dot-row dot-row-between" style="margin-bottom:1.5rem;">
        <div>
            <h1 class="dot-title">Settlement payments</h1>
            <div class="dot-subtitle">Every Paystack payment attempt and every payment event that matched nothing. Read-only.</div>
        </div>
        @include('settlements._mode', ['payments' => $config])
    </div>

    @if($config->isMisconfigured())
        <div role="alert" class="dot-alert dot-alert-error">{{ $config->problem() }}</div>
    @elseif(! $config->isConfigured())
        <div class="dot-alert dot-alert-info">Online payment is not switched on (no Paystack key is set), so winners cannot pay.</div>
    @endif

    <div class="dot-alert {{ $attention > 0 ? 'dot-alert-error' : 'dot-alert-ok' }}" role="status">
        @if($attention > 0)
            <strong>{{ $attention === 1 ? '1 payment needs an operator' : $attention.' payments need an operator' }}.</strong>
            Money was reported that could not be applied to a sale. Nothing was marked paid; decide in Paystack's dashboard whether to refund or reconcile by hand.
        @else
            No payments need an operator.
        @endif
    </div>

    <div class="dot-row" style="margin-bottom:1rem;">
        <a href="{{ route('operator.settlement-payments.index') }}" class="dot-btn dot-btn-small {{ $attentionOnly ? 'dot-btn-ghost' : 'dot-btn-primary' }}">All</a>
        <a href="{{ route('operator.settlement-payments.index', ['show' => 'attention']) }}" class="dot-btn dot-btn-small {{ $attentionOnly ? 'dot-btn-primary' : 'dot-btn-ghost' }}">Needs attention</a>
    </div>

    <div class="dot-panel" style="overflow:hidden;">
        @if($payments->count())
        <div style="overflow-x:auto;">
            <table class="dot-table">
                <caption class="sr-only">Settlement payments</caption>
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
                    @foreach($payments as $payment)
                    <tr>
                        <td class="dot-mono" style="overflow-wrap:anywhere;max-width:240px;">{{ $payment->reference }}</td>
                        <td>
                            <span class="dot-status dot-status-{{ $payment->status }}">{{ ucfirst($payment->status === 'unmatched' ? 'needs an operator' : $payment->status) }}</span>
                            @if($payment->reason)<div style="margin-top:4px;font-weight:600;color:#fca5a5;">{{ $payment->reasonLabel() }}</div>@endif
                            @if($payment->summary)<div class="dot-help" style="max-width:320px;">{{ $payment->summary }}</div>@endif
                        </td>
                        <td class="num dot-mono">{{ $payment->amount_cents === null ? '—' : \App\Models\Auction::money($payment->amount_cents / 100) }}</td>
                        <td>
                            @if($payment->settlement)
                                {{ $payment->settlement->auction->title ?? 'Auction #'.$payment->settlement->auction_id }}
                                <div class="dot-help">Sale is {{ str_replace('_', ' ', $payment->settlement->status) }}</div>
                            @else
                                <span class="dot-muted">Matches no sale</span>
                            @endif
                        </td>
                        <td>{{ $payment->created_at->format('j M Y, H:i') }} {{ config('app.timezone') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:2.5rem 1.4rem;text-align:center;">
            <p style="margin:0;font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">{{ $attentionOnly ? 'Nothing needs attention.' : 'No payments have been recorded yet.' }}</p>
        </div>
        @endif
    </div>

    <div style="margin-top:1.25rem;">{{ $payments->links() }}</div>
</div>
</x-app-layout>
