<x-guest-layout title="Confirm your password" description="Confirm your password to continue to a secure area.">
    <p class="gx-card__alt">This is a secure area. Confirm your password to continue.</p>


    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <x-ds.input label="Password" name="password" type="password" required autocomplete="current-password" autofocus />
        <div class="gx-card__row">
            <span></span>
            <x-ds.button type="submit" size="lg">Confirm</x-ds.button>
        </div>
    </form>
</x-guest-layout>
