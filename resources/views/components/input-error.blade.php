@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'ds-error']) }}><x-ds.icon name="alert" :size="14" /> <span>{{ $message }}</span></p>
@enderror
