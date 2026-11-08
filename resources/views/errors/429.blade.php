@extends('errors._ecosystem-layout', ['code' => 429])

@section('icon', 'pause')
@section('title', "Slow down a little")
@section('message', "You have made a lot of requests in a short time. This protects everyone's access. Wait a minute and try again.")

@section('actions')
    <x-ds.button href="/">Go home</x-ds.button>
@endsection
