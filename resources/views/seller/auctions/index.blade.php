<x-app-layout>
<div class="dot-page">

    <div class="dot-row dot-row-between" style="margin-bottom:1.5rem;">
        <div>
            <h1 class="dot-title">My Auctions</h1>
            <div class="dot-subtitle">Everything you have listed. A draft stays private until you publish it.</div>
        </div>
        <a href="{{ route('seller.auctions.create') }}" class="dot-btn dot-btn-primary">List an auction</a>
    </div>

    @include('seller.auctions._notices')

    <div class="dot-panel" style="overflow:hidden;">
        @include('seller.auctions._table', ['auctions' => $auctions])
    </div>

    <div style="margin-top:1.25rem;">{{ $auctions->links() }}</div>

</div>
</x-app-layout>
