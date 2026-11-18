{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout :title="$headline" description="Signing in to Dot.Auction through InfoDot did not finish.">
    <x-ds.alert tone="danger">{{ $body }}</x-ds.alert>

    <div class="gx-card__row">
        @if ($retry)<x-ds.button :href="$retryUrl" size="lg">Start again</x-ds.button>@endif
        <x-ds.button :href="$homeUrl" variant="quiet" size="lg">Back to Dot.Auction</x-ds.button>
    </div>
    <p class="gx-card__alt">Reference: {{ $code }}</p>
</x-guest-layout>
