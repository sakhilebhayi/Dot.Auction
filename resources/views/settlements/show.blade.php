{{--
  One sale, for its two parties. The top band is the whole answer: what state it is in, how much, and the one next step for THIS viewer.
  The rest is reference: the facts, the payout note (sellers, once), and the history of what moved the sale.
--}}
@php
    use App\Support\Display;

    $title = $settlement->auction->title ?? 'Lot #'.$settlement->auction_id;
    $status = $settlement->status;
    $canPayNow = $payments->canTakePayments();
    $payable = $settlement->isPayable();
    $test = $payments->isTestMode();

    // The state of the page in words: one line that says what is going on, then (at most) one action.
    $headline = match (true) {
        $isWinner && $status === 'paid' => 'Payment received. Thank you.',
        $isWinner && $status === 'cancelled' => 'Cancelled by the seller.',
        $isWinner && $payable && $canPayNow => $status === 'payment_overdue' ? 'Payment is overdue.' : 'Pay to complete your purchase.',
        $isWinner => 'Nothing is due yet.',
        $status === 'paid' => 'The buyer has paid.',
        $status === 'cancelled' => 'You cancelled this sale.',
        $status === 'payment_overdue' => 'The buyer has not paid on time.',
        default => 'Waiting for the buyer to pay.',
    };

    // Why, in one or two sentences.
    $detail = match (true) {
        $isWinner && $status === 'paid' => 'The seller has been told.'.($test ? ' This was a test payment: no real money moved.' : ''),
        $isWinner && $status === 'cancelled' => 'The seller cancelled this sale because payment was overdue. Nothing is due and it can no longer be paid.',
        $isWinner && $payable && $canPayNow => $status === 'payment_overdue' ? 'You can still pay until the seller cancels the sale.' : null,
        $isWinner => 'Payment is not available yet, so nothing is due now. You will be told when the payment step opens.',
        $status === 'paid' => null,
        $status === 'cancelled' => 'The buyer has been told and can no longer pay. The auction has not been relisted.',
        $status === 'payment_overdue' => 'They can still pay until you cancel the sale. Dot.Auction does not relist or cancel anything for you.',
        $canPayNow => 'If they do not pay by the deadline the sale is marked overdue and you decide what to do.',
        default => 'Payment is not available yet, so the sale is recorded as awaiting payment.',
    };

    // The Paystack return, titled. Only the first alert of the page shows.
    $return = session('payment_state');
    $returnMessage = session('status') ?: $errors->first('payment');
    $returnTone = ['paid' => 'success', 'pending' => 'notice', 'failed' => 'danger', 'held' => 'notice', 'unconfirmed' => 'notice', 'gateway' => 'danger'][$return] ?? null;
    $returnTitle = [
        'paid' => 'Payment received',
        'pending' => 'Payment is being confirmed',
        'failed' => 'The payment did not go through',
        'held' => 'The payment needs a person to look at it',
        'unconfirmed' => 'We could not confirm your payment yet',
        'gateway' => 'Payment could not be started',
    ][$return] ?? null;
    $problems = collect($errors->all())->reject(fn ($m) => $m === $returnMessage && $returnTone !== null)->values();

    $deadlineLabel = $status === 'payment_overdue' ? 'Was due' : 'Pay by';
@endphp
<x-app-layout :title="'Payment for '.$title" description="Where this sale stands and what to do next." width="narrow" :back="route('settlements.index')" back-label="All payments">
    {{-- No header slot: the layout prints the title prop as the h1, escaped once. A header slot would double-escape a lot title that contains & or <. --}}
    <x-slot name="meta"><x-ds.status :state="$status" /></x-slot>

    @if ($returnTone && $returnMessage)
        <x-ds.alert :tone="$returnTone" :title="$returnTitle" class="sl-gap">{{ $returnMessage }}</x-ds.alert>
    @elseif (session('status'))
        <x-ds.alert tone="success" class="sl-gap" role="status">{{ session('status') }}</x-ds.alert>
    @endif
    @foreach ($problems as $problem)
        <x-ds.alert tone="danger" class="sl-gap">{{ $problem }}</x-ds.alert>
    @endforeach

    <x-ds.section tone="surface" class="pay-next" aria-label="Next step">
        <p class="pay-next__label">{{ $isWinner ? 'You owe' : 'Sold for' }}</p>
        <p class="pay-next__amount"><x-ds.money :amount="$settlement->amount_cents / 100" size="money" /></p>
        <p class="pay-next__headline">{{ $headline }}</p>
        @if ($detail)<p class="pay-next__detail">{{ $detail }}</p>@endif

        @if ($payable && $canPayNow && $settlement->payment_due_at)
            <p class="pay-next__due">
                <x-ds.icon name="clock" :size="18" />
                <span>{{ $deadlineLabel }} <strong>{{ Display::time($settlement->payment_due_at, 'j M Y, H:i', true) }}</strong>, {{ $settlement->payment_due_at->diffForHumans() }}</span>
            </p>
        @endif

        @if ($isWinner && $payable && $canPayNow)
            <form method="POST" action="{{ route('settlements.pay', $settlement) }}" class="pay-next__action">
                @csrf
                <x-ds.button type="submit" size="lg" icon="lock">Pay now</x-ds.button>
                <p class="ds-hint">
                    You are taken to Paystack to pay {{ $settlement->amount() }}. Card details are entered on Paystack, never on Dot.Auction.
                    @if ($test) No real money moves in test mode. @endif
                </p>
            </form>
        @elseif ($isSeller && $status === 'payment_overdue')
            <div class="pay-next__action">
                <x-ds.confirm :action="route('settlements.cancel', $settlement)" variant="danger" icon="ban" size="lg"
                    title="Cancel this sale?" body="The buyer is told and can no longer pay. It does not relist the auction and does not refund anyone."
                    confirm="Cancel sale" cancel="Keep sale">Cancel this sale</x-ds.confirm>
                <p class="ds-hint">Cancelling does not relist the auction and does not refund anyone. The buyer is told, and can no longer pay.</p>
            </div>
        @endif
    </x-ds.section>

    <x-ds.section title="Details" class="sl-section">
        <x-ds.dl>
            <div class="ds-dl__row"><dt>Lot</dt><dd><a class="sl-link" href="{{ route('auctions.show', $settlement->auction) }}">{{ $title }}</a></dd></div>
            <div class="ds-dl__row"><dt>Your part</dt><dd>{{ $isWinner ? 'You won this lot' : 'You sold this lot' }}</dd></div>
            <div class="ds-dl__row"><dt>Sale closed</dt><dd>{{ Display::time($settlement->auction->ends_at, 'j M Y, H:i', true) }}</dd></div>
            @if ($status === 'paid' && $settlement->paid_at)
                <div class="ds-dl__row"><dt>Paid</dt><dd>{{ Display::time($settlement->paid_at, 'j M Y, H:i', true) }}</dd></div>
                @if ($receipt)<div class="ds-dl__row"><dt>Reference</dt><dd class="pay-ref">{{ $receipt->reference }}</dd></div>@endif
            @elseif ($status === 'cancelled' && $settlement->cancelled_at)
                <div class="ds-dl__row"><dt>Cancelled</dt><dd>{{ Display::time($settlement->cancelled_at, 'j M Y, H:i', true) }}</dd></div>
            @endif
        </x-ds.dl>
    </x-ds.section>

    @if ($isSeller && $status !== 'cancelled')
        <x-ds.section title="Your payout" class="sl-section">
            <p class="pay-note">
                Payout is not available yet.
                @if ($status === 'paid')
                    Dot.Auction has received the buyer's payment and pays sellers out by hand: payment received, settlement to seller is manual. The money is with Dot.Auction, not with you, until it is paid out.
                @else
                    When payment is received, settlement to you is manual.
                @endif
            </p>
        </x-ds.section>
    @endif

    <x-ds.section title="History" class="sl-section">
        <ol class="pay-history">
            <li><time datetime="{{ $settlement->created_at->toIso8601ZuluString() }}">{{ Display::time($settlement->created_at, 'j M Y, H:i') }}</time><span>Sale recorded at {{ $settlement->amount() }}.</span></li>
            @foreach ($settlement->events as $event)
                @php
                    $what = match ($event->type) {
                        'payment_opened' => 'Payment opened for the buyer.',
                        'overdue' => 'The payment deadline passed and the sale was marked overdue.',
                        'paid' => 'Payment received.',
                        'cancelled' => $isSeller ? 'You cancelled the sale.' : 'The seller cancelled the sale.',
                        default => ucfirst(str_replace('_', ' ', (string) $event->type)).'.',
                    };
                @endphp
                <li><time datetime="{{ $event->created_at->toIso8601ZuluString() }}">{{ Display::time($event->created_at, 'j M Y, H:i') }}</time><span>{{ $what }}</span></li>
            @endforeach
        </ol>
    </x-ds.section>
</x-app-layout>
