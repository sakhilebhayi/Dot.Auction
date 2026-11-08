<x-guest-layout title="Sign in" description="Sign in to Dot.Auction to bid, watch lots and manage your sales.">

    @session('status')
        <x-ds.alert tone="info">{{ $value }}</x-ds.alert>
    @endsession

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <x-ds.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />
        <x-ds.input label="Password" name="password" type="password" required autocomplete="current-password" />
        <x-ds.checkbox label="Keep me signed in on this device" name="remember" />
        <div class="gx-card__row">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="gx-card__alt-link">Forgot your password?</a>
            @endif
            <x-ds.button type="submit" size="lg">Sign in</x-ds.button>
        </div>
    </form>
    <p class="gx-card__alt">Dot.Auction accounts come from InfoDot. Open Dot.Auction from InfoDot to create yours.</p>
</x-guest-layout>
