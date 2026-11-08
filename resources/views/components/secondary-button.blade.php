{{-- Jetstream's x-secondary-button, re-skinned as the quiet button. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'ds-btn ds-btn--quiet']) }}>{{ $slot }}</button>
