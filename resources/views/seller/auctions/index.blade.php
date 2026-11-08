@php
    use App\Support\SellerLots;
    $tabs = [['label' => 'All lots', 'href' => route('seller.auctions.index'), 'current' => $state === null, 'count' => $counts['all']]];
    foreach (SellerLots::STATES as $key => $label) {
        if ($counts[$key] > 0 || $state === $key) {
            $tabs[] = ['label' => $label, 'href' => route('seller.auctions.index', ['state' => $key]), 'current' => $state === $key, 'count' => $counts[$key]];
        }
    }
@endphp
<x-app-layout title="My lots" description="Every lot you have listed: what is live, what needs your decision, and what is next.">
    <x-slot name="header">My lots</x-slot>
    <x-slot name="actions"><x-ds.button :href="route('seller.auctions.create')" icon="plus">List a lot</x-ds.button></x-slot>

    @include('seller.auctions._notices')

    @if ($counts['pending'] > 0 && $state !== 'pending' && ($state !== null || $auctions->currentPage() > 1))
        <x-ds.alert tone="notice" :title="$counts['pending'] === 1 ? '1 lot is waiting for your decision' : $counts['pending'].' lots are waiting for your decision'" class="sl-gap">
            {{ $counts['pending'] === 1 ? 'It ended below your reserve.' : 'They ended below your reserve.' }}
            <a href="{{ route('seller.auctions.index', ['state' => 'pending']) }}">Decide now</a>
        </x-ds.alert>
    @endif

    @if ($counts['all'] === 0)
        <x-ds.empty-state title="You have not listed anything yet" icon="tag" body="Start a draft, add photos, then publish when you are ready for bids. A draft stays private until you publish it.">
            <x-ds.button :href="route('seller.auctions.create')" icon="plus">List a lot</x-ds.button>
        </x-ds.empty-state>
    @else
        <x-ds.tabs :tabs="$tabs" label="Filter your lots by state" />

        @if ($auctions->isEmpty())
            <x-ds.empty-state class="sl-gap-top" title="No lots in this view" icon="tag" body="Nothing is in this state at the moment.">
                <x-ds.button :href="route('seller.auctions.index')" variant="quiet">Show all lots</x-ds.button>
            </x-ds.empty-state>
        @else
            @include('seller.auctions._lots', ['auctions' => $auctions, 'proposals' => $proposals, 'settlements' => $settlements])
            <x-ds.pagination :paginator="$auctions" noun="lots" />
        @endif
    @endif
</x-app-layout>
