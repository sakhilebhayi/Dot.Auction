{{-- Seller's own auctions. $auctions carries bids_count (withCount). --}}
@if($auctions->count())
<div style="overflow-x:auto;">
    <table class="dot-table">
        <caption class="sr-only">Your auctions</caption>
        <thead>
            <tr>
                <th scope="col">Auction</th>
                <th scope="col">Status</th>
                <th scope="col" class="num">Bids</th>
                <th scope="col" class="num">Current price</th>
                <th scope="col">Closes</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($auctions as $row)
            @php($status = $row->displayStatus())
            <tr>
                <td>
                    <div style="font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;max-width:260px;overflow-wrap:anywhere;">
                        @if($status === 'draft')
                            <a class="dot-link" style="color:#f4f4f5;" href="{{ route('seller.auctions.edit', $row) }}">{{ $row->title }}</a>
                        @else
                            <a class="dot-link" style="color:#f4f4f5;" href="{{ route('auctions.show', $row) }}">{{ $row->title }}</a>
                        @endif
                    </div>
                    <div class="dot-help" style="margin-top:2px;">
                        {{ $row->category->name ?? 'No category' }}
                        @if($row->buy_now_price) &middot; Buy now {{ \App\Models\Auction::money($row->buy_now_price) }} @endif
                    </div>
                </td>
                <td><span class="dot-status dot-status-{{ $status }}">{{ $status === 'pending_review' ? 'Needs your decision' : ucfirst($status) }}</span></td>
                <td class="num dot-mono">{{ $row->bids_count }}</td>
                <td class="num dot-mono">
                    {{ \App\Models\Auction::money($row->current_price) }}
                    <div class="dot-help" style="margin-top:2px;">Started at {{ \App\Models\Auction::money($row->starting_price) }}</div>
                </td>
                <td>
                    {{ $row->ends_at->format('M j, Y') }}
                    <div class="dot-help" style="margin-top:2px;">{{ $row->ends_at->format('H:i') }}</div>
                </td>
                <td>
                    <div class="dot-actions">
                        @can('update', $row)
                            <a href="{{ route('seller.auctions.edit', $row) }}" class="dot-btn dot-btn-ghost dot-btn-small">Edit<span class="sr-only"> {{ $row->title }}</span></a>
                        @endcan
                        @if($row->status === 'draft')
                            <form method="POST" action="{{ route('seller.auctions.publish', $row) }}">
                                @csrf
                                <button type="submit" class="dot-btn dot-btn-primary dot-btn-small">Publish<span class="sr-only"> {{ $row->title }}</span></button>
                            </form>
                        @else
                            <a href="{{ route('auctions.show', $row) }}" class="dot-btn dot-btn-ghost dot-btn-small">View<span class="sr-only"> {{ $row->title }}</span></a>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@else
<div style="padding:2.5rem 1.4rem;text-align:center;">
    <p style="margin:0 0 0.4rem;font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">You have not listed anything yet.</p>
    <p class="dot-help" style="margin:0 0 1.1rem;">Start a draft, check it over, then publish when you are ready for bids.</p>
    <a href="{{ route('seller.auctions.create') }}" class="dot-btn dot-btn-primary">List an auction</a>
</div>
@endif
