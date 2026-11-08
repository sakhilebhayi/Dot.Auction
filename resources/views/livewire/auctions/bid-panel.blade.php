{{--
  The bidding column of a lot page (Livewire BidPanel). Everything the viewer needs to bid, in order: what the lot is (status), where the price is,
  how long is left, what the viewer's own position is, the form with its confirm step, Buy Now, the payment panel and the bid history.
  Copy for every state and viewer comes from App\Support\LotOutcome; this file only lays it out.
  Live: resources/js/auction-live.js polls $wire.snapshot() and calls refreshState() when the picture changed. The data-* on the root are its inputs.
--}}
@php
    use App\Models\Auction;
    use App\Support\Display;

    $a = $this->auction;
    $live = $o->isLive();
    $state = $o->state;
    $min = Auction::money($o->minimumCents / 100);
    $step = Auction::money($a->bid_increment);
    $window = (int) config('auction.snipe_window_minutes', 5);
    $extra = (int) config('auction.snipe_extension_minutes', 5);
    $maxExt = (int) config('auction.snipe_max_extensions', 6);
    $extensions = (int) $a->snipe_extensions;
    $yourBid = $o->viewerBidCents !== null ? $o->viewerBidCents / 100 : null;
    $fieldError = $errorField === 'bidAmount' ? $error : null;
    $panelError = $errorField === null ? $error : null;
    $inLateWindow = $live && $window > 0 && $extensions < $maxExt && $a->ends_at->lte(now()->addMinutes($window));
    $topLabel = $live ? 'Leading' : ($state === 'sold' ? 'Winning bid' : 'Highest bid');
@endphp
<div class="lot-bid" data-auction-live data-sig="{{ $sig }}" data-poll="{{ $poll }}" data-realtime="{{ $realtime ? 1 : 0 }}" @if ($state === 'closing') data-settling @endif>
    <section class="lot-bid__main" aria-labelledby="lot-bid-title-{{ $a->id }}">
        <h2 class="sr-only" id="lot-bid-title-{{ $a->id }}">Bidding</h2>

        {{-- Where the price is and how long is left --}}
        <div class="lot-bid__top">
            <div class="lot-bid__tags">
                <x-ds.status :state="$o->statusState()" />
                @if ($live && $extensions > 0)<x-ds.status state="extended" :count="$extensions" />@endif
                @if (! $o->isSeller && in_array($state, ['scheduled', 'live', 'ending', 'closing', 'pending'], true))
                    <livewire:auctions.watchlist-button :auction="$a" :key="'watch-'.$a->id" />
                @endif
            </div>

            <div class="lot-price">
                <p class="lot-price__label">
                    <span>{{ $o->priceLabel }}</span>
                    <span class="lot-price__meta">
                        @if (in_array($state, ['scheduled', 'draft'], true))
                            bid step {{ $step }}
                        @elseif ($o->bidCount === 0)
                            No bids yet
                        @else
                            {{ $o->bidCount }} {{ $o->bidCount === 1 ? 'bid' : 'bids' }}
                        @endif
                    </span>
                </p>
                <x-ds.money :amount="$o->price" size="money" class="lot-price__amount" />
            </div>

            @if ($state === 'scheduled')
                <div class="lot-clock">
                    <x-ds.countdown :ends="$a->ends_at" :starts="$a->starts_at" size="lg" />
                </div>
            @elseif ($live)
                <div class="lot-clock">
                    <x-ds.countdown :ends="$a->ends_at" size="lg" />
                    <x-ds.rail :starts="$a->starts_at" :ends="$a->ends_at" :extensions="$extensions" thick class="lot-clock__rail" />
                </div>
                @if ($extensions > 0)
                    <p class="lot-note">Closing time extended {{ $extensions }} {{ $extensions === 1 ? 'time' : 'times' }} by late bids. The mark on the rail shows the first closing time.</p>
                @endif
                @if ($inLateWindow)
                    <p class="lot-note is-strong">Closing now: a bid adds {{ $extra }} {{ $extra === 1 ? 'minute' : 'minutes' }} to the clock.</p>
                @endif
            @elseif ($state === 'closing')
                <div class="lot-clock">
                    <x-ds.countdown :ends="$a->ends_at" size="lg" />
                </div>
            @elseif (in_array($state, ['sold', 'unsold', 'pending'], true))
                <p class="lot-clock__ended">Closed {{ Display::time($a->ends_at, 'D j M, H:i', true) }}</p>
            @endif
        </div>

        {{-- What it means for this viewer, then what they can do --}}
        <div class="lot-bid__act">
            @if ($o->box)
                <div class="lot-outcome lot-outcome--{{ $o->box['tone'] }}" data-outcome="{{ $state }}:{{ $o->role }}">
                    <x-ds.icon :name="$o->box['icon']" :size="22" class="lot-outcome__icon" />
                    <div class="lot-outcome__text">
                        <h3 class="lot-outcome__title">{{ $o->box['headline'] }}</h3>
                        @if ($success && $o->role === 'leader')<p class="lot-outcome__done">{{ $success }}</p>@endif
                        @foreach ($o->box['lines'] as $line)<p>{{ $line }}</p>@endforeach
                        @if ($live && $o->role === 'outbid' && $o->canBid)
                            <x-ds.button size="md" class="lot-outcome__action" wire:click="bidMinimum" wire:loading.attr="disabled" wire:target="bidMinimum,review">
                                Bid {{ $min }}
                            </x-ds.button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($state === 'pending' && $o->role === 'seller' && $o->proposal && $o->proposal->status === 'pending')
                <livewire:auctions.reserve-decision-panel :proposal="$o->proposal" :key="'reserve-'.$o->proposal->id" />
            @endif

            @if ($success && ! in_array($o->role, ['leader', 'winner'], true))
                <x-ds.alert tone="success" role="group" :title="$purchased ? 'Lot bought' : 'Bid placed'">{{ $success }}</x-ds.alert>
            @endif
            @if ($panelError)
                <x-ds.alert tone="danger">{{ $panelError }}</x-ds.alert>
            @endif

            @if ($o->canBid)
                <form class="lot-form" wire:submit="review" novalidate>
                    <x-ds.input
                        name="bidAmount" id="bid-amount-{{ $a->id }}" label="Your bid" type="text" money
                        wire:model="bidAmount" data-ds-bid-input data-prefill="{{ $bidAmount }}" autocomplete="off" spellcheck="false"
                        :hint="($o->bidCount === 0 ? 'Opening bid is at least ' : 'The next valid bid is ').$min.'. Bids rise in steps of '.$step.'.'"
                        :error="$fieldError"
                    />
                    <x-ds.button type="submit" size="lg" block wire:loading.attr="disabled" wire:target="review">
                        <span wire:loading.remove wire:target="review">Place bid</span>
                        <span wire:loading wire:target="review">Checking your bid</span>
                    </x-ds.button>
                    <p class="lot-form__note">You confirm on the next step. A bid cannot be withdrawn.</p>
                </form>
            @endif

            @if ($live)
                <x-ds.bid-ladder :auction="$a" :your-bid="$yourBid" :leading="$o->viewerLeads" :steps="3" :interactive="$o->canBid" class="lot-ladder" />
            @endif

            @if ($o->canBuyNow)
                <div class="lot-buynow">
                    <div>
                        <p class="lot-buynow__title">Buy now for {{ Auction::money($a->buy_now_price) }}</p>
                        <p class="lot-buynow__text">Skip the bidding. You win at once and the lot closes.</p>
                    </div>
                    <x-ds.button variant="quiet" data-dialog-open="{{ $buyNowId }}">Buy now</x-ds.button>
                </div>
            @endif

            @if ($purchased || ($o->state === 'sold' && $o->role === 'winner' && $o->settlement))
                <p class="lot-note">
                    @if ($o->settlement)
                        <a class="lot-link" href="{{ route('settlements.show', $o->settlement) }}">Open the payment page for this sale</a>
                    @else
                        Your payment details appear on this page as soon as the sale is recorded.
                    @endif
                </p>
            @endif
        </div>

        @if ($o->settlement && in_array($o->role, ['winner', 'seller'], true))
            @include('auctions._settlement-panel', ['settlement' => $o->settlement])
        @endif
    </section>

    {{-- Bid history: newest first, names masked, times in the display zone --}}
    <section class="lot-bid__history" aria-labelledby="lot-history-title-{{ $a->id }}">
        <div class="lot-history__head">
            <h2 class="lot-h2" id="lot-history-title-{{ $a->id }}">Bid history</h2>
            <p class="lot-history__zone">Times in {{ Display::time($a->ends_at, 'T') === 'SAST' ? 'South African time' : Display::time($a->ends_at, 'T') }}</p>
        </div>

        @if ($this->recentBids->isEmpty())
            <p class="lot-history__empty">
                @if (in_array($state, ['scheduled', 'draft'], true))
                    Bidding has not opened. Bids will be listed here.
                @elseif ($live)
                    No bids yet. The first bid has to be at least {{ $min }}.
                @else
                    Nobody bid on this lot.
                @endif
            </p>
        @else
            <ol class="lot-history">
                @foreach ($this->recentBids as $bid)
                    @php($mine = auth()->id() === $bid->bidder_id)
                    <li class="lot-history__row {{ $bid->is_winning ? 'is-top' : '' }}">
                        <span class="lot-history__who">
                            {{ $mine ? 'You' : $bid->bidder->maskedName() }}
                            @if ($mine)<span class="lot-history__you">Your bid</span>@endif
                        </span>
                        <time class="lot-history__when" datetime="{{ $bid->created_at->toIso8601ZuluString() }}">{{ Display::time($bid->created_at, 'j M, H:i:s') }}</time>
                        <span class="lot-history__amount"><x-ds.money :amount="$bid->amount" size="md" /></span>
                        @if ($bid->is_winning)<span class="lot-history__top">{{ $topLabel }}</span>@endif
                    </li>
                @endforeach
            </ol>
            @if ($o->bidCount > 10)
                <button type="button" class="ds-btn ds-btn--link lot-history__more" wire:click="toggleAllBids">
                    {{ $showAllBids ? 'Show the latest 10' : 'Show all '.$o->bidCount.' bids' }}
                </button>
            @endif
        @endif
    </section>

    {{-- Heard by screen readers when something changed on its own: never on every tick --}}
    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-live-announce>{{ $announcement }}</p>
    <p class="lot-live-lost" data-live-lost hidden role="status">Live updates paused. Trying again.</p>

    {{-- Confirm a bid --}}
    @if ($o->canBid)
        <x-ds.dialog :id="$confirmId" :title="'Place a bid of '.($confirmAmount !== '' ? $confirmAmount : $min).'?'" size="sm" wire:ignore.self>
            <div class="ds-confirm">
                <p class="ds-confirm__body">A bid is binding and cannot be withdrawn. If it is the highest when the lot closes, you buy the lot at this price.</p>
                @if ($inLateWindow)<p class="ds-confirm__body">This lot closes soon, so your bid adds {{ $extra }} {{ $extra === 1 ? 'minute' : 'minutes' }} to the clock.</p>@endif
                <div class="ds-dialog__foot">
                    <x-ds.button variant="quiet" data-dialog-close wire:click="cancelReview">Change amount</x-ds.button>
                    <x-ds.button autofocus wire:click="placeBid" wire:loading.attr="disabled" wire:target="placeBid">
                        <span wire:loading.remove wire:target="placeBid">Place bid of {{ $confirmAmount !== '' ? $confirmAmount : $min }}</span>
                        <span wire:loading wire:target="placeBid">Placing bid</span>
                    </x-ds.button>
                </div>
            </div>
        </x-ds.dialog>
    @endif

    {{-- Confirm Buy Now --}}
    @if ($o->canBuyNow)
        <x-ds.dialog :id="$buyNowId" :title="'Buy now for '.Auction::money($a->buy_now_price).'?'" size="sm" wire:ignore.self>
            <div class="ds-confirm">
                <p class="ds-confirm__body">Bidding ends straight away and you win this lot at {{ Auction::money($a->buy_now_price) }}. Like a bid, it is binding.</p>
                <p class="ds-confirm__body">After you confirm, this page shows the payment step for the sale.</p>
                <div class="ds-dialog__foot">
                    <x-ds.button variant="quiet" data-dialog-close>Keep bidding</x-ds.button>
                    <x-ds.button autofocus wire:click="buyNow" wire:loading.attr="disabled" wire:target="buyNow">
                        <span wire:loading.remove wire:target="buyNow">Buy now for {{ Auction::money($a->buy_now_price) }}</span>
                        <span wire:loading wire:target="buyNow">Buying</span>
                    </x-ds.button>
                </div>
            </div>
        </x-ds.dialog>
    @endif
</div>
