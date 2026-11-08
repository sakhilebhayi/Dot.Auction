@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon', 'sliders')
@section('title', "We'll be right back")
@section('message', "Dot.Auction is briefly down for maintenance. Nothing you were working on has been lost. Check back shortly.")

@section('actions')
    <x-ds.button href="/">Try again</x-ds.button>
@endsection
