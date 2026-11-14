{{--
  Shown (HTTP 403) when the person's company does not hold Dot.Auction in InfoDot: at sign-in when no company is left,
  and in the signed-in area when the current company's access has ended. It carries no auction data at all.
  $kind: off | suspended | payment | ended | company (a personal workspace: the platform needs a company). $company: the company's name or null. $platformsUrl: InfoDot's page for this
  platform. $switchTo: the person's other teams (may be empty). One action: open Platforms in InfoDot.
--}}
@extends('errors._ecosystem-layout', ['code' => 403, 'discover' => false])

@php
    $for = $company ? 'for '.$company : 'for your company';
    [$title, $message] = match ($kind) {
        'company' => ['Dot.Auction needs a company that has switched it on', 'A personal workspace holds the free core only. Create a company in InfoDot or join one, and have Dot.Auction switched on there. Until then the signed-in pages stay closed.'],
        'suspended' => ['Dot.Auction is suspended '.$for, 'InfoDot has suspended this platform for the company, so the signed-in pages are closed for now. A company owner or admin can see why in InfoDot.'],
        'payment' => ['A payment is needed to keep using Dot.Auction', 'The signed-in pages are closed '.$for.' until the payment is settled in InfoDot. A company owner or admin can do that there.'],
        'ended' => ['Dot.Auction access has ended '.$for, 'The plan or trial that covered this platform has run out, so the signed-in pages are closed. A company owner or admin can renew it in InfoDot.'],
        default => ['Dot.Auction is not switched on '.$for, 'A company owner or admin can switch this platform on in InfoDot. Until then the signed-in pages stay closed.'],
    };
@endphp

@section('icon', 'lock')
@section('title', $title)
@section('message')
    {{ $message }} Nothing has been removed: lots still close on time and bids already placed still stand.
@endsection

@section('actions')
    <x-ds.button :href="$platformsUrl">Open Platforms in InfoDot</x-ds.button>
@endsection

@if ($switchTo->isNotEmpty())
    @section('after')
        <section class="er-discover er-switch" aria-labelledby="er-switch">
            <h2 id="er-switch">{{ $kind === 'company' ? 'Or carry on as one of your companies' : 'Or carry on as another of your companies' }}</h2>
            <ul>
                @foreach ($switchTo as $team)
                    <li>
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <x-ds.button type="submit" variant="quiet">{{ $team->name }}</x-ds.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endsection
@endif
