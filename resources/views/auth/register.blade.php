<x-guest-layout title="Create an account" description="Create a Dot.Auction account.">

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <x-ds.input label="Name" name="name" type="text" :value="old('name')" required autofocus autocomplete="name" />
        <x-ds.input label="Email" name="email" type="email" :value="old('email')" required autocomplete="username" />
        <x-ds.input label="Password" name="password" type="password" required autocomplete="new-password" />
        <x-ds.input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />
        @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
            <div class="ds-field">
                <label class="ds-check" for="terms">
                    <input type="checkbox" id="terms" name="terms" value="1" required>
                    <span class="ds-check__box" aria-hidden="true"><x-ds.icon name="check" :size="14" /></span>
                    <span class="ds-check__text">I agree to the <a target="_blank" href="{{ route('terms.show') }}">Terms of Service</a> and the <a target="_blank" href="{{ route('policy.show') }}">Privacy Policy</a></span>
                </label>
            </div>
        @endif
        <div class="gx-card__row">
            <a href="{{ route('login') }}" class="gx-card__alt-link">Already registered?</a>
            <x-ds.button type="submit" size="lg">Create account</x-ds.button>
        </div>
    </form>
</x-guest-layout>
