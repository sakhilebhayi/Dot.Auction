@extends('errors._ecosystem-layout', ['code' => 403])

@section('icon', 'lock')
@section('title', "You don't have access to this")
@section('message', "Either this belongs to a different team, needs a role you don't have, or needs you to sign in first. If you think this is wrong, ask whoever owns the workspace.")

@section('actions')
    <x-ds.button href="/">Go home</x-ds.button>
    @if ((bool) rescue(fn () => auth()->check(), false, false))
        <x-ds.button :href="Route::has('dashboard') ? route('dashboard') : '/'" variant="quiet">Go to dashboard</x-ds.button>
    @elseif (Route::has('login'))
        <x-ds.button :href="route('login')" variant="quiet">Sign in</x-ds.button>
    @endif
@endsection
