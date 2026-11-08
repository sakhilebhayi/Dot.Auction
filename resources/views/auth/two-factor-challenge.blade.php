{{-- The sign-in pages load no JavaScript framework, so the switch between an authentication code and a recovery code is a plain link (?recovery=1), not an Alpine toggle. --}}
@php $recovery = request()->boolean('recovery'); @endphp
<x-guest-layout title="Two-step check" description="Enter the code from your authenticator app to finish signing in.">
    <div class="gx-stack">
        <p class="gx-card__alt">{{ $recovery ? 'Enter one of your emergency recovery codes.' : 'Enter the code from your authenticator app.' }}</p>

        <form method="POST" action="{{ route('two-factor.login') }}">
            @csrf
            @if ($recovery)
                <x-ds.input label="Recovery code" name="recovery_code" type="text" autofocus autocomplete="one-time-code" />
            @else
                <x-ds.input label="Authentication code" name="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code" />
            @endif
            <div class="gx-card__row">
                @if ($recovery)
                    <a class="ds-btn ds-btn--link" href="{{ route('two-factor.login') }}"><span class="ds-btn__label">Use an authentication code</span></a>
                @else
                    <a class="ds-btn ds-btn--link" href="{{ route('two-factor.login', ['recovery' => 1]) }}"><span class="ds-btn__label">Use a recovery code</span></a>
                @endif
                <x-ds.button type="submit" size="lg">Sign in</x-ds.button>
            </div>
        </form>
    </div>
</x-guest-layout>
