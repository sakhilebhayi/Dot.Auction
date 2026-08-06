@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'text-sm text-[var(--red-soft)]']) }}>{{ $message }}</p>
@enderror
