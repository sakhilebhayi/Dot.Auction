@props(['disabled' => false])

{{-- Jetstream's x-input: a bare input in the design system's control style. Prefer <x-ds.input>, which adds the label, hint and error wiring. --}}
<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'ds-in']) !!}>
