@props(['value'])

<label {{ $attributes->merge(['class' => 'ds-label']) }}>{{ $value ?? $slot }}</label>
