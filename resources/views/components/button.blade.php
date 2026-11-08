{{-- Jetstream's x-button, re-skinned: the design system's primary button (see components/ds/button.blade.php). Type defaults to submit, as in Jetstream. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ds-btn ds-btn--primary']) }}>{{ $slot }}</button>
