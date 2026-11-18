{{-- R-54: the claim page, in Rostrum. Shown when an account here already uses the email address of the InfoDot sign-in.
     Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout title="Confirm it is you" description="Enter the code we emailed you to connect your InfoDot sign-in to your Dot.Auction account.">
    <p class="gx-card__alt">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your bids, lots and settlements stay exactly as they are.</p>

    @if (session('dotsso_status'))
        <x-ds.alert tone="info">{{ session('dotsso_status') }}</x-ds.alert>
    @endif

    <form method="POST" action="{{ $verifyUrl }}" class="gx-claim" novalidate>
        @csrf
        <div aria-live="polite">
        <x-ds.input label="Code from the email" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
            maxlength="{{ $codeLength }}" required autofocus class="gx-claim__code"
            hint="It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries." />
        </div>
        <x-ds.button type="submit" size="lg" block>Confirm and continue</x-ds.button>
    </form>

    <div class="gx-card__row">
        <form method="POST" action="{{ $resendUrl }}">@csrf<x-ds.button type="submit" variant="link">Send a new code</x-ds.button></form>
        <form method="POST" action="{{ $cancelUrl }}">@csrf<x-ds.button type="submit" variant="link">Cancel</x-ds.button></form>
    </div>
</x-guest-layout>
