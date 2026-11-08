{{--
  One sale, for its two parties. The top band is the whole answer: what state it is in, how much, and the one next step for THIS viewer.
  The rest is reference: the facts, the payout note (sellers, once), and the history of what moved the sale.
--}}
@php
    use App\Support\Display;
    use App\Support\SettlementPosition;

    $title = $settlement->auction->title ?? 'Lot #'.$settlement->auction_id;
    $status = $settlement->status;
    // One answer for every page: can this be paid now, is it late, what is owed (App\Support\SettlementPosition).
    $position = SettlementPosition::of($settlement, $payments);
    $canPayNow = $position->canPayNow();
    $test = $payments->isTestMode();

    // The state of the page in words: one line that says what is going on, then (at most) one action.
    $headline = $position->headline($isWinner);
    $detail = $position->detail($isWinner, $test);

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

    $deadlineLabel = $position->isOverdue() ? 'Was due' : 'Pay by';
@endphp
<x-app-layout :title="'Payment for '.$title" description="Where this sale stands and what to do next." width="narrow" :back="route('settlements.index')" back-label="All payments">
    {{-- No header slot: the layout prints the title prop as the h1, escaped once. A header slot would double-escape a lot title that contains & or <. --}}
    <x-slot name="meta"><x-ds.status :state="$position->badge()" /></x-slot>

    @if ($returnTone && $returnMessage)
        <x-ds.alert :tone="$returnTone" :title="$returnTitle" class="sl-gap">{{ $returnMessage }}</x-ds.alert>
    @elseif (session('status'))
        <x-ds.alert tone="success" class="sl-gap" role="status">{{ session('status') }}</x-ds.alert>
    @endif
    @foreach ($problems as $problem)
        <x-ds.alert tone="danger" class="sl-gap">{{ $problem }}</x-ds.alert>
    @endforeach

    <x-ds.section tone="surface" class="pay-next" aria-label="Next step">
        <p class="pay-next__label">{{ $position->amountLabel($isWinner) }}</p>
        <p class="pay-next__amount"><x-ds.money :amount="$settlement->amount_cents / 100" size="money" /></p>
        <p class="pay-next__headline">{{ $headline }}</p>
        @if ($detail)<p class="pay-next__detail">{{ $detail }}</p>@endif

        @if ($position->showsDeadline())
            <p class="pay-next__due">
                <x-ds.icon name="clock" :size="18" />
                <span>{{ $deadlineLabel }} <strong>{{ Display::time($settlement->payment_due_at, 'j M Y, H:i', true) }}</strong>, {{ $settlement->payment_due_at->diffForHumans() }}</span>
            </p>
        @endif

        @if ($isWinner && $canPayNow)
            <form method="POST" action="{{ route('settlements.pay', $settlement) }}" class="pay-next__action">
                @csrf
                <x-ds.button type="submit" size="lg" icon="lock">Pay now</x-ds.button>
                <p class="ds-hint">
                    You are taken to Paystack to pay {{ $settlement->amount() }}. Card details are entered on Paystack, never on Dot.Auction.
                    @if ($test) No real money moves in test mode. @endif
                </p>
            </form>
        @elseif ($isSeller && $position->sellerCanCancel())
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
            <div class="ds-dl__row"><dt>Your role</dt><dd>{{ $isWinner ? 'Buyer' : 'Seller' }}</dd></div>
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
                {{-- While payment is closed, "payment opened" and "deadline passed" would contradict the page: they are history of a state that is not current. --}}
                @continue($position->isWaiting() && in_array($event->type, ['payment_opened', 'overdue'], true))
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
