@extends('errors._ecosystem-layout', ['code' => 419])

@section('icon', 'clock')
@section('title', "Your session timed out")
@section('message', "For your security a form expires after a while without activity. Nothing was lost. Go back and send it again.")

@section('actions')
    <x-ds.button :href="url()->previous() === url()->current() ? '/' : url()->previous()">Go back and try again</x-ds.button>
    <x-ds.button href="/" variant="quiet">Go home</x-ds.button>
@endsection
