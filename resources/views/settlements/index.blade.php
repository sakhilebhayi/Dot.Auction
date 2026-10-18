<x-app-layout>
<div class="dot-page">

    <div class="dot-row dot-row-between" style="margin-bottom:1.5rem;">
        <div>
            <h1 class="dot-title">Payments</h1>
            <div class="dot-subtitle">Auctions you won and auctions you sold. Winners pay Dot.Auction; paying sellers out is a manual step.</div>
        </div>
        @include('settlements._mode', ['payments' => $payments])
    </div>

    <div class="dot-panel" style="overflow:hidden;">
        @if($settlements->count())
        <div style="overflow-x:auto;">
            <table class="dot-table">
                <caption class="sr-only">Your settlements</caption>
                <thead>
                    <tr>
                        <th scope="col">Auction</th>
                        <th scope="col">You</th>
                        <th scope="col" class="num">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($settlements as $row)
                    @php($won = $row->winner_id === auth()->id())
                    <tr>
                        <td style="font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;max-width:280px;overflow-wrap:anywhere;">
                            <a class="dot-link" style="color:#f4f4f5;" href="{{ route('settlements.show', $row) }}">{{ $row->auction->title }}</a>
                        </td>
                        <td>{{ $won ? 'You won' : 'You sold' }}</td>
                        <td class="num dot-mono">{{ $row->amount() }}</td>
                        <td>
                            <span class="dot-status dot-status-{{ $row->status }}">{{ $row->statusLabel() }}</span>
                            @if($row->isPayable() && $row->payment_due_at)
                                <div class="dot-help" style="margin-top:2px;">{{ $row->status === 'payment_overdue' ? 'Was due' : 'Pay by' }} {{ $row->payment_due_at->format('j M, H:i') }}</div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('settlements.show', $row) }}" class="dot-btn dot-btn-ghost dot-btn-small">
                                {{ $won && $row->isPayable() && $payments->canTakePayments() ? 'Pay' : 'View' }}<span class="sr-only"> {{ $row->auction->title }}</span>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:2.5rem 1.4rem;text-align:center;">
            <p style="margin:0 0 0.4rem;font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">Nothing to pay and nothing sold yet.</p>
            <p class="dot-help" style="margin:0 0 1.1rem;">When you win an auction, or one of yours sells, it appears here.</p>
            <a href="{{ route('auctions.index') }}" class="dot-btn dot-btn-primary">Browse auctions</a>
        </div>
        @endif
    </div>

    <div style="margin-top:1.25rem;">{{ $settlements->links() }}</div>
</div>
</x-app-layout>
