@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-mono text-xs uppercase tracking-wide text-[var(--stone)]']) }}>
    {{ $value ?? $slot }}
</label>
