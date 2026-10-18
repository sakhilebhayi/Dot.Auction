@php($zone = config('app.timezone'))
<x-app-layout>
<div class="dot-page dot-page-narrow">

    <div style="margin-bottom:1.25rem;">
        <a href="{{ route('settlements.index') }}" class="dot-link" style="font-size:0.78rem;color:#71717a;font-weight:500;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;" aria-hidden="true">arrow_back</span>
            All payments
        </a>
    </div>

    <div class="dot-row dot-row-between" style="margin-bottom:1.5rem;align-items:flex-start;">
        <div>
            <h1 class="dot-title">Payment for {{ $settlement->auction->title }}</h1>
            <div class="dot-subtitle">
                {{ $isWinner ? 'You won this auction.' : 'You sold this auction.' }}
                <a class="dot-link" href="{{ route('auctions.show', $settlement->auction) }}">View the auction</a>
            </div>
        </div>
        @include('settlements._mode', ['payments' => $payments])
    </div>

    @if(session('status'))
        <div role="status" class="dot-alert dot-alert-ok">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="dot-alert dot-alert-error">
            @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif

    <div class="dot-panel">
        <div class="dot-panel-head">
            <h2 class="dot-section-title">{{ $settlement->statusLabel() }}</h2>
            <span class="dot-status dot-status-{{ $settlement->status }}">{{ $settlement->statusLabel() }}</span>
        </div>
        <div class="dot-panel-body">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <div class="dot-label" style="color:#a1a1aa;">{{ $isWinner ? 'You owe' : 'Sold for' }}</div>
                    <div class="dot-mono" style="font-size:1.5rem;font-weight:700;color:#f4f4f5;">{{ $settlement->amount() }}</div>
                    <div class="dot-help">{{ $settlement->currency }}, as agreed when the auction closed</div>
                </div>
                @if($settlement->status === 'paid' && $settlement->paid_at)
                    <div>
                        <div class="dot-label" style="color:#a1a1aa;">Paid</div>
                        <div class="dot-mono" style="color:#f4f4f5;">{{ $settlement->paid_at->format('j M Y, H:i') }} {{ $zone }}</div>
                        @if($receipt)<div class="dot-help">Reference <span class="dot-mono">{{ $receipt->reference }}</span></div>@endif
                    </div>
                @elseif($settlement->isPayable() && $settlement->payment_due_at)
                    <div>
                        <div class="dot-label" style="color:#a1a1aa;">{{ $settlement->status === 'payment_overdue' ? 'Was due' : 'Pay by' }}</div>
                        <div class="dot-mono" style="color:#f4f4f5;">{{ $settlement->payment_due_at->format('j M Y, H:i') }} {{ $zone }}</div>
                        <div class="dot-help">{{ $settlement->payment_due_at->diffForHumans() }}</div>
                    </div>
                @endif
            </div>

            @if($isWinner)
                @if($settlement->status === 'paid')
                    <p class="dot-help" style="font-size:13px;margin:0;">Payment received. Thank you. The seller has been told. {{ $payments->isTestMode() ? 'This was a test payment: no real money moved.' : '' }}</p>
                @elseif($settlement->status === 'cancelled')
                    <p class="dot-help" style="font-size:13px;margin:0;">The seller cancelled this sale because payment was overdue. Nothing is due and it can no longer be paid.</p>
                @elseif($payments->canTakePayments())
                    @if($settlement->status === 'payment_overdue')
                        <p class="dot-help" style="font-size:13px;margin-top:0;">Payment is overdue, but you can still pay until the seller cancels the sale.</p>
                    @endif
                    <form method="POST" action="{{ route('settlements.pay', $settlement) }}" class="dot-row">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-primary">Pay now</button>
                        <span class="dot-help" style="margin:0;">
                            You are taken to Paystack to pay {{ $settlement->amount() }}. Card details are entered on Paystack, never on Dot.Auction.
                            @if($payments->isTestMode()) No real money moves in test mode. @endif
                        </span>
                    </form>
                @else
                    <p class="dot-help" style="font-size:13px;margin:0;">
                        Payment is not available yet, so nothing is due now. You will be told when the payment step opens.
                    </p>
                @endif
            @else
                @if($settlement->status === 'awaiting_payment')
                    <p class="dot-help" style="font-size:13px;margin-top:0;">
                        @if($payments->canTakePayments())
                            Waiting for the buyer to pay. If they do not pay by the deadline this sale is marked overdue and you decide what to do.
                        @else
                            Payment is not available yet, so the sale is recorded as awaiting payment.
                        @endif
                    </p>
                @elseif($settlement->status === 'payment_overdue')
                    <p class="dot-help" style="font-size:13px;margin-top:0;">The buyer has not paid by the deadline. They can still pay until you cancel. Dot.Auction does not relist or cancel anything for you.</p>
                @elseif($settlement->status === 'cancelled')
                    <p class="dot-help" style="font-size:13px;margin-top:0;">You cancelled this sale{{ $settlement->cancelled_at ? ' on '.$settlement->cancelled_at->format('j M Y').'' : '' }}. The auction has not been relisted.</p>
                @endif

                <p class="dot-help" style="font-size:13px;margin-bottom:0;">
                    @if($settlement->status === 'paid')
                        Payout is not available yet &mdash; payment received, settlement to seller is manual. The money is with Dot.Auction, not with you, until it is paid out.
                    @else
                        Payout is not available yet. When payment is received, settlement to you is manual.
                    @endif
                </p>

                @if($settlement->status === 'payment_overdue')
                    <form method="POST" action="{{ route('settlements.cancel', $settlement) }}" style="margin-top:1.25rem;">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-danger" onclick="return confirm('Cancel this sale? The buyer will be told and can no longer pay.');">Cancel this sale</button>
                        <div class="dot-help">Cancelling this sale does not relist the auction and does not refund anyone. The buyer is told, and can no longer pay.</div>
                    </form>
                @endif
            @endif

        </div>
    </div>
</div>
</x-app-layout>
