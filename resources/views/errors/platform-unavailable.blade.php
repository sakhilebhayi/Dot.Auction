{{--
  Shown with a 503 when InfoDot has switched Dot.Auction off (maintenance or unavailable). It says so plainly and reads
  nothing of the auctions: lots, bids and settlements are kept exactly as they are. It is not a purchase or permission
  problem. $message: InfoDot's wording or null. $signedIn: offer sign out.
--}}
@extends('errors._ecosystem-layout', ['code' => 503, 'discover' => false])

@section('icon', 'sliders')
@section('title', 'Dot.Auction is not available right now')
@section('message', ($message ?? 'Dot.Auction is temporarily unavailable.').' Nothing has been removed: lots and bids are kept as they are.')

@section('actions')
    <x-ds.button href="/">Try again</x-ds.button>
    @if ($signedIn ?? false)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <x-ds.button type="submit" variant="quiet">Sign out</x-ds.button>
        </form>
    @endif
@endsection
