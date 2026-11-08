<x-guest-layout title="Choose a new password" description="Set a new Dot.Auction password.">

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-ds.input label="Email" name="email" type="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
        <x-ds.input label="New password" name="password" type="password" required autocomplete="new-password" />
        <x-ds.input label="Confirm new password" name="password_confirmation" type="password" required autocomplete="new-password" />
        <div class="gx-card__row">
            <span></span>
            <x-ds.button type="submit" size="lg">Save new password</x-ds.button>
        </div>
    </form>
</x-guest-layout>
