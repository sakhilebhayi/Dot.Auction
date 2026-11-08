{{-- Jetstream's x-danger-button, re-skinned as the danger button (solid red inside dialogs). --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'ds-btn ds-btn--danger']) }}>{{ $slot }}</button>
