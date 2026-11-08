{{--
  The payment panel of a lot page. Shown inside the bid panel to the winner and the seller only ($settlement is null for everyone else).
  Words only for the state; the one action is a button to the payment page. Honest about what is not built: payout is manual, payment may be closed.
--}}
@php
    $payments = app(\App\Services\Paystack\PaystackConfig::class);
    $isWinner = $settlement->winner_id === auth()->id();
    $title = match (true) {
        $settlement->status === 'paid' => 'Payment received',
        $settlement->status === 'cancelled' => 'Sale cancelled',
        $settlement->status === 'payment_overdue' => 'Payment overdue',
        $payments->canTakePayments() => 'Payment due',
        default => 'Payment pending',
    };
@endphp
<div class="lot-pay">
    <div class="lot-pay__head">
        <h3 class="lot-pay__title">{{ $title }}</h3>
        <x-ds.status :state="$settlement->status" size="sm" />
        @include('settlements._mode', ['payments' => $payments])
    </div>

    @if ($settlement->status === 'paid')
        <p class="lot-pay__text">
            @if ($isWinner)
                You paid {{ $settlement->amount() }}. Thank you.
            @else
                The buyer paid {{ $settlement->amount() }} to Dot.Auction. Payout is not available yet: payment is received and paying you out is a manual step.
            @endif
        </p>
    @elseif ($settlement->status === 'cancelled')
        <p class="lot-pay__text">The seller cancelled this sale. Nothing is due.</p>
    @elseif ($payments->canTakePayments())
        @if ($isWinner)
            <p class="lot-pay__text">
                You won this lot at {{ $settlement->amount() }}.
                @if ($settlement->payment_due_at) Pay by @localtime($settlement->payment_due_at, 'j M Y, H:i', true). @endif
            </p>
            <x-ds.button :href="route('settlements.show', $settlement)" block>Pay now</x-ds.button>
        @else
            <p class="lot-pay__text">
                Sold for {{ $settlement->amount() }}. {{ $settlement->status === 'payment_overdue' ? 'The buyer is overdue.' : 'Waiting for the buyer to pay.' }}
                Payout is not available yet: when payment is received, paying you out is a manual step.
            </p>
            <x-ds.button variant="quiet" :href="route('settlements.show', $settlement)" block>Open payment page</x-ds.button>
        @endif
    @else
        @if ($isWinner)
            <p class="lot-pay__text">
                You won this lot at {{ $settlement->amount() }}. Dot.Auction has not opened payment yet, so nothing is due now.
                You will be told when the payment step opens.
            </p>
        @else
            <p class="lot-pay__text">
                Sold for {{ $settlement->amount() }}. The sale is recorded as awaiting payment; Dot.Auction does not collect payment yet.
                You will be told when the payment step opens.
            </p>
        @endif
    @endif
</div>
