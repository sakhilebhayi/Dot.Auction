{{--
  The seller's lots as a ledger: a table from 40rem, stacked rows on phones (ds.table), every row with its next action visible.
  In: $auctions (paginator; images, category, winningBid and bids_count loaded), $proposals (pending reserve decisions keyed by auction id),
      $settlements (the payment behind a sold lot, keyed by auction id).
--}}
@php
    use App\Models\Auction;
    use App\Support\Display;
@endphp
<x-ds.table caption="Your lots" class="sl-table sl-cards">
    <thead>
        <tr>
            <th scope="col">Lot</th>
            <th scope="col">Status</th>
            <th scope="col" class="num">Bids</th>
            <th scope="col" class="num">Price</th>
            <th scope="col">Closes</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($auctions as $row)
            @php
                $lot = Display::lotState($row);
                $proposal = $proposals->get($row->id);
                $settlement = $settlements->get($row->id);
                $cover = $row->images->first();
                $hasBids = $row->bids_count > 0;
                $editable = in_array($row->status, ['draft', 'active'], true);
                $priceNote = match ($lot) {
                    'sold' => 'Sold for',
                    'unsold', 'pending' => $hasBids ? 'Highest bid' : 'No bids',
                    'scheduled', 'draft', 'cancelled' => 'Starting bid',
                    default => $hasBids ? 'Current bid' : 'Starting bid',
                };
                $price = in_array($lot, ['scheduled', 'draft', 'cancelled'], true) || ($lot === 'unsold' && ! $hasBids) ? $row->starting_price : $row->current_price;
                $viewHref = route('auctions.show', $row);
                $menuId = 'lot-menu-'.$row->id;
                $canCancel = $row->status === 'active' && ! $hasBids;
                // Closed by the clock but not yet settled (the job runs every few minutes): not "sold" and not "not sold" yet.
                $settling = $row->status === 'active' && $row->ends_at->isPast();
            @endphp
            <tr class="sl-row sl-row--{{ $lot }}">
                <td class="sl-lot">
                    <span class="sl-thumb">
                        @if ($cover)
                            <img src="{{ $row->imageUrl($cover, 'thumb') }}" alt="" width="64" height="64" loading="lazy" decoding="async">
                        @else
                            <x-ds.icon name="image" :size="22" />
                        @endif
                    </span>
                    <span class="sl-lot__text">
                        <a class="sl-lot__title" href="{{ $row->status === 'draft' ? route('seller.auctions.edit', $row) : $viewHref }}">{{ $row->title }}</a>
                        <span class="sl-lot__meta">{{ $row->category->name ?? 'No category' }}</span>
                        @if ($row->buy_now_price)<span class="sl-lot__meta">Buy now {{ Auction::money($row->buy_now_price) }}</span>@endif
                    </span>
                </td>
                <td data-label="Status">
                    @if ($settling)
                        <x-ds.badge tone="notice" icon="clock">Closed, being settled</x-ds.badge>
                    @else
                        <x-ds.status :state="$lot" size="sm" />
                    @endif
                    @if ($lot === 'sold' && $settlement)
                        <span class="sl-sub">{{ $settlement->status === 'cancelled' ? 'Sale cancelled' : $settlement->statusLabel() }}</span>
                    @endif
                </td>
                <td data-label="Bids" class="num">{{ $row->bids_count }}</td>
                <td data-label="Price" class="num">
                    <x-ds.money :amount="$price" :muted="in_array($lot, ['draft', 'cancelled'], true)" />
                    <span class="sl-sub">{{ $priceNote }}</span>
                </td>
                <td data-label="Closes" class="sl-closes">
                    @if (in_array($lot, ['live', 'ending'], true))
                        <x-ds.countdown :ends="$row->ends_at" size="sm" />
                    @elseif ($lot === 'scheduled')
                        <x-ds.countdown :ends="$row->ends_at" :starts="$row->starts_at" size="sm" />
                    @elseif ($lot === 'draft')
                        {{ Display::time($row->ends_at, 'j M Y, H:i') }}
                        <span class="sl-sub">Planned close, not published</span>
                    @else
                        {{ Display::time($row->ends_at, 'j M Y, H:i') }}
                        <span class="sl-sub">Closed</span>
                    @endif
                </td>
                <td class="actions sl-actions">
                    <div class="sl-actions__row">
                        @if ($row->status === 'draft')
                            <x-ds.confirm :action="route('seller.auctions.publish', $row)" size="sm" variant="primary" tone="primary"
                                :title="'Publish “'.$row->title.'”?'"
                                body="Buyers will be able to see it and bidding opens at the opening time you set, or right away if you set none. Once someone bids, the price, reserve and closing time are locked."
                                confirm="Publish lot" cancel="Not yet">Publish<span class="sr-only"> {{ $row->title }}</span></x-ds.confirm>
                            <x-ds.button :href="route('seller.auctions.edit', $row)" variant="quiet" size="sm">Edit<span class="sr-only"> {{ $row->title }}</span></x-ds.button>
                        @elseif ($lot === 'sold' && $settlement)
                            <x-ds.button :href="route('settlements.show', $settlement)" variant="quiet" size="sm">View payment<span class="sr-only"> for {{ $row->title }}</span></x-ds.button>
                        @else
                            <x-ds.button :href="$viewHref" variant="quiet" size="sm">View lot<span class="sr-only"> {{ $row->title }}</span></x-ds.button>
                            @if ($editable)
                                <x-ds.button :href="route('seller.auctions.edit', $row)" variant="quiet" size="sm">Edit<span class="sr-only"> {{ $row->title }}</span></x-ds.button>
                            @endif
                        @endif

                        @if ($row->status === 'draft' || $canCancel || ($lot === 'sold' && $settlement))
                            <x-ds.dropdown :id="$menuId" :label="'More actions for '.$row->title" :icon-only="true" class="sl-more">
                                <x-slot name="trigger"><x-ds.icon name="dots" :size="20" /></x-slot>
                                @if ($row->status === 'draft')
                                    <x-ds.dropdown-item data-dialog-open="delete-lot-{{ $row->id }}" icon="trash" :danger="true">Delete draft</x-ds.dropdown-item>
                                @else
                                    <x-ds.dropdown-item :href="$viewHref" icon="eye">View lot</x-ds.dropdown-item>
                                @endif
                                @if ($canCancel)
                                    <x-ds.dropdown-item data-dialog-open="cancel-lot-{{ $row->id }}" icon="ban" :danger="true">Cancel lot</x-ds.dropdown-item>
                                @endif
                            </x-ds.dropdown>
                        @endif
                    </div>

                    @if ($row->status === 'draft')
                        <x-ds.confirm-dialog :id="'delete-lot-'.$row->id" :action="route('seller.auctions.destroy', $row)" method="DELETE"
                            :title="'Delete “'.$row->title.'”?'" body="The draft and its photos are removed for good. This cannot be undone."
                            confirm="Delete draft" cancel="Keep draft" />
                    @endif
                    @if ($canCancel)
                        <x-ds.confirm-dialog :id="'cancel-lot-'.$row->id" :action="route('seller.auctions.cancel', $row)"
                            :title="'Cancel “'.$row->title.'”?'" body="Buyers will no longer be able to see it or bid. You can cancel only while nobody has bid, and it cannot be reopened."
                            confirm="Cancel lot" cancel="Keep lot" />
                    @endif
                </td>
            </tr>
            @if ($proposal)
                <tr class="sl-decision">
                    <td colspan="6">
                        <div class="sl-decision__box">
                            <p class="sl-decision__lead"><strong>Your decision.</strong> This lot closed below your reserve. Sell to the top bid, or end it unsold.</p>
                            <livewire:auctions.reserve-decision-panel :proposal="$proposal" :key="'reserve-'.$proposal->id" />
                        </div>
                    </td>
                </tr>
            @endif
        @endforeach
    </tbody>
</x-ds.table>
