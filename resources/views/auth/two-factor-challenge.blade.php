<x-guest-layout title="Two-step check" description="Enter the code from your authenticator app to finish signing in.">
    <div x-data="{ recovery: false }" class="gx-stack">
        <p class="gx-card__alt" x-show="! recovery">Enter the code from your authenticator app.</p>
        <p class="gx-card__alt" x-cloak x-show="recovery">Enter one of your emergency recovery codes.</p>


        <form method="POST" action="{{ route('two-factor.login') }}">
            @csrf
            <div x-show="! recovery">
                <x-ds.input label="Authentication code" name="code" type="text" inputmode="numeric" autofocus x-ref="code" autocomplete="one-time-code" />
            </div>
            <div x-cloak x-show="recovery">
                <x-ds.input label="Recovery code" name="recovery_code" type="text" x-ref="recovery_code" autocomplete="one-time-code" />
            </div>
            <div class="gx-card__row">
                <button type="button" class="ds-btn ds-btn--link" x-show="! recovery" x-on:click="recovery = true; $nextTick(() => { $refs.recovery_code.focus() })">Use a recovery code</button>
                <button type="button" class="ds-btn ds-btn--link" x-cloak x-show="recovery" x-on:click="recovery = false; $nextTick(() => { $refs.code.focus() })">Use an authentication code</button>
                <x-ds.button type="submit" size="lg">Sign in</x-ds.button>
            </div>
        </form>
    </div>
</x-guest-layout>
