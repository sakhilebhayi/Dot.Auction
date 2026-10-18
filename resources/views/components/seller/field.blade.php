@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
    'prefix' => null,
    'options' => [],
    'placeholder' => null,
    'span' => false,
])
@php
    $hasError = $errors->has($name);
    $describedBy = collect([$help ? $name.'-help' : null, $hasError ? $name.'-error' : null])->filter()->implode(' ');
    $current = old($name, $value);
@endphp
<div @class(['dot-span-all' => $span])>
    <label for="{{ $name }}" class="dot-label">
        {{ $label }}
        @if($required)
            <span aria-hidden="true">*</span><span class="sr-only">(required)</span>
        @else
            <span class="dot-muted" style="font-weight:400;">(optional)</span>
        @endif
    </label>

    @if($type === 'textarea')
        <textarea
            id="{{ $name }}" name="{{ $name }}" rows="6"
            {{ $attributes->merge(['class' => 'dot-input']) }}
            @if($required) required @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        >{{ $current }}</textarea>
    @elseif($type === 'select')
        <select
            id="{{ $name }}" name="{{ $name }}"
            {{ $attributes->merge(['class' => 'dot-input']) }}
            @if($required) required @endif
            @if($hasError) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        >
            <option value="">{{ $placeholder ?? 'Choose one' }}</option>
            @foreach($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <div @class(['dot-prefix' => $prefix])>
            @if($prefix)<span aria-hidden="true">{{ $prefix }}</span>@endif
            <input
                id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $current }}"
                {{ $attributes->merge(['class' => 'dot-input']) }}
                @if($required) required @endif
                @if($placeholder) placeholder="{{ $placeholder }}" @endif
                @if($hasError) aria-invalid="true" @endif
                @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            >
        </div>
    @endif

    @if($help)
        <p id="{{ $name }}-help" class="dot-help">{{ $help }}</p>
    @endif
    @error($name)
        <p id="{{ $name }}-error" class="dot-error">{{ $message }}</p>
    @enderror
</div>
