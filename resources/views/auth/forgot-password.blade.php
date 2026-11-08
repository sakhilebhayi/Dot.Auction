<x-guest-layout title="Reset your password" description="Ask for a link to choose a new Dot.Auction password.">
    <p class="gx-card__alt">Enter your email address and we will send you a link to choose a new password.</p>

    @session('status')
        <x-ds.alert tone="success">{{ $value }}</x-ds.alert>
    @endsession


    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-ds.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />
        <div class="gx-card__row">
            <a href="{{ route('login') }}" class="gx-card__alt-link">Back to sign in</a>
            <x-ds.button type="submit" size="lg">Email me a reset link</x-ds.button>
        </div>
    </form>
</x-guest-layout>
