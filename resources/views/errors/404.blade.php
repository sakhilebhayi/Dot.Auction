@extends('errors._ecosystem-layout', ['code' => 404])

@section('icon', 'search')
@section('title', "We couldn't find that page")
@section('message', "The page may have moved, been renamed or never existed. Check the link, or start again from the home page.")

@section('actions')
    <x-ds.button href="/">Go home</x-ds.button>
    @if ((bool) rescue(fn () => auth()->check(), false, false))
        <x-ds.button :href="Route::has('dashboard') ? route('dashboard') : '/'" variant="quiet">Go to dashboard</x-ds.button>
    @elseif (Route::has('login'))
        <x-ds.button :href="route('login')" variant="quiet">Sign in</x-ds.button>
    @endif
@endsection
