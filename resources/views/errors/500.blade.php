@extends('errors._ecosystem-layout', ['code' => 500])

@section('icon', 'alert')
@section('title', "Something went wrong on our end")
@section('message', "That is on us, not you. An unexpected error interrupted this page. Try again in a moment, and tell us if it keeps happening.")

@section('actions')
    <x-ds.button href="/">Go home</x-ds.button>
    @if (Route::has('contact'))
        <x-ds.button :href="route('contact')" variant="quiet">Contact support</x-ds.button>
    @endif
@endsection
