<x-guest-layout title="Verify your email" description="Confirm your email address to continue to Dot.Auction.">
    <p class="gx-card__alt">Before you continue, open the link we emailed you to verify your address. If it has not arrived, we can send another.</p>

    @if (session('status') == 'verification-link-sent')
        <x-ds.alert tone="success">A new verification link has been sent to the address on your profile.</x-ds.alert>
    @endif

    <div class="gx-card__row">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ds.button type="submit">Resend verification email</x-ds.button>
        </form>
        <div class="gx-card__row">
            <a href="{{ route('profile.show') }}" class="gx-card__alt-link">Edit profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ds.button type="submit" variant="link">Log out</x-ds.button>
            </form>
        </div>
    </div>
</x-guest-layout>
