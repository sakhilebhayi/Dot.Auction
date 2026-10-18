{{-- Payment panel on the auction page. Shown to the winner and the seller only ($settlement is null for everyone else). --}}
@php($payments = app(\App\Services\Paystack\PaystackConfig::class))
@php($isWinner = $settlement->winner_id === auth()->id())
<div class="dot-panel" style="margin-top:1rem;">
    <div class="dot-panel-body">
        <div class="dot-row dot-row-between" style="margin-bottom:0.4rem;">
            <h2 class="dot-section-title">
                @if($settlement->status === 'paid') Payment received
                @elseif($settlement->status === 'cancelled') Sale cancelled
                @elseif($settlement->status === 'payment_overdue') Payment overdue
                @elseif($payments->canTakePayments()) Payment due
                @else Payment pending
                @endif
            </h2>
            @include('settlements._mode', ['payments' => $payments])
        </div>

        @if($settlement->status === 'paid')
            <p class="dot-help" style="font-size:13px;">
                @if($isWinner)
                    You paid {{ $settlement->amount() }}. Thank you.
                @else
                    The buyer paid {{ $settlement->amount() }} to Dot.Auction. Payout is not available yet &mdash; payment received, settlement to seller is manual.
                @endif
            </p>
        @elseif($settlement->status === 'cancelled')
            <p class="dot-help" style="font-size:13px;">The seller cancelled this sale. Nothing is due.</p>
        @elseif($payments->canTakePayments())
            @if($isWinner)
                <p class="dot-help" style="font-size:13px;">
                    You won this auction at {{ $settlement->amount() }}.
                    @if($settlement->payment_due_at) Pay by {{ $settlement->payment_due_at->format('j M Y, H:i') }} {{ config('app.timezone') }}. @endif
                </p>
                <a href="{{ route('settlements.show', $settlement) }}" class="dot-btn dot-btn-primary">Pay now</a>
            @else
                <p class="dot-help" style="font-size:13px;">
                    Sold for {{ $settlement->amount() }}. {{ $settlement->status === 'payment_overdue' ? 'The buyer is overdue.' : 'Waiting for the buyer to pay.' }}
                    Payout is not available yet: when payment is received, settlement to you is manual.
                </p>
                <a href="{{ route('settlements.show', $settlement) }}" class="dot-btn dot-btn-ghost">Open payment page</a>
            @endif
        @else
            @if($isWinner)
                <p class="dot-help" style="font-size:13px;">
                    You won this auction at {{ $settlement->amount() }}. Dot.Auction has not opened payment yet, so nothing is due now.
                    You will be told when the payment step opens.
                </p>
            @else
                <p class="dot-help" style="font-size:13px;">
                    Sold for {{ $settlement->amount() }}. The sale is recorded as awaiting payment; Dot.Auction does not collect payment yet.
                    You will be told when the payment step opens.
                </p>
            @endif
        @endif
    </div>
</div>
