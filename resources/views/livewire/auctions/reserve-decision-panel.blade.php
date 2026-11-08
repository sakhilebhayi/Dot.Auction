{{--
  The seller's decision on a lot that ended below its reserve. Two real choices, each behind a confirm dialog that says what will happen.
  Livewire ReserveDecisionPanel (public API: proposal, accept(), reject()). Used on the lot page and wherever a seller's pending decisions are listed.
  The reserve amount is shown here because only the seller can ever reach this component (Gate review on both actions).
--}}
@php
    use App\Models\Auction;
    use App\Support\Display;
    $bid = $proposal->bid;
    $title = $proposal->auction->title;
    $top = Auction::money($bid->amount);
    $who = $bid->bidder->maskedName();
@endphp
<div class="lot-decide" id="reserve-decision-{{ $proposal->id }}">
    @if ($proposal->status === 'pending')
        <h3 class="lot-decide__title">Decide on the highest bid for {{ $title }}</h3>
        <x-ds.dl class="lot-decide__facts">
            <div class="ds-dl__row"><dt>Highest bid</dt><dd>{{ $top }} from {{ $who }}</dd></div>
            <div class="ds-dl__row"><dt>Your reserve</dt><dd>{{ Auction::money($proposal->reserve_price) }}</dd></div>
            @if ($proposal->awaiting_since)<div class="ds-dl__row"><dt>Waiting since</dt><dd>{{ Display::time($proposal->awaiting_since, 'D j M, H:i', true) }}</dd></div>@endif
        </x-ds.dl>
        <p class="lot-decide__help">Accept it and {{ $who }} wins the lot at {{ $top }}, and a payment step is created. Reject it and the lot ends without a sale. You cannot change your mind afterwards.</p>
        <div class="lot-decide__actions">
            <x-ds.button data-dialog-open="accept-bid-{{ $proposal->id }}">Accept {{ $top }}</x-ds.button>
            <x-ds.button variant="danger" data-dialog-open="reject-bid-{{ $proposal->id }}">Reject the bid</x-ds.button>
        </div>

        <x-ds.dialog :id="'accept-bid-'.$proposal->id" :title="'Accept '.$top.' from '.$who.'?'" size="sm">
            <div class="ds-confirm">
                <p class="ds-confirm__body">The lot sells at {{ $top }}, below your reserve of {{ Auction::money($proposal->reserve_price) }}. {{ $who }} is told they won, and the payment step opens. This cannot be undone.</p>
                <div class="ds-dialog__foot">
                    <x-ds.button variant="quiet" data-dialog-close>Not yet</x-ds.button>
                    <x-ds.button autofocus wire:click="accept" wire:loading.attr="disabled" wire:target="accept">
                        <span wire:loading.remove wire:target="accept">Accept {{ $top }}</span>
                        <span wire:loading wire:target="accept">Accepting</span>
                    </x-ds.button>
                </div>
            </div>
        </x-ds.dialog>

        <x-ds.dialog :id="'reject-bid-'.$proposal->id" title="End this lot without a sale?" size="sm">
            <div class="ds-confirm">
                <p class="ds-confirm__body">The lot ends unsold and {{ $who }} does not win it. This cannot be undone.</p>
                <div class="ds-dialog__foot">
                    <x-ds.button variant="quiet" data-dialog-close>Keep deciding</x-ds.button>
                    <x-ds.button variant="danger" wire:click="reject" wire:loading.attr="disabled" wire:target="reject">
                        <span wire:loading.remove wire:target="reject">End without a sale</span>
                        <span wire:loading wire:target="reject">Ending</span>
                    </x-ds.button>
                </div>
            </div>
        </x-ds.dialog>
    @else
        <x-ds.alert :tone="$proposal->status === 'accepted' ? 'success' : 'info'" role="group" :title="$proposal->status === 'accepted' ? 'You accepted the highest bid' : 'You ended the lot without a sale'">
            @if ($proposal->status === 'accepted')
                {{ $title }} sold for {{ $top }} to {{ $who }} on {{ Display::time($proposal->decided_at, 'j M Y, H:i', true) }}.
            @else
                You rejected {{ $top }} from {{ $who }} on {{ Display::time($proposal->decided_at, 'j M Y, H:i', true) }}. {{ $title }} was not sold.
            @endif
        </x-ds.alert>
    @endif
</div>
