{{--
  ds.select: a native select (best on phones) with the same wiring as ds.input.
  Props: label, name, id=null, value=null (selected; old(name)), options=[] (value => label), placeholder=null (a first empty option), hint=null, error=null, optional=false, bag='default'.
  Or pass <option> elements in the slot. Attributes pass to the <select>; class goes to the wrapper.
--}}
@props(['label', 'name', 'id' => null, 'value' => null, 'options' => [], 'placeholder' => null, 'hint' => null, 'error' => null, 'optional' => false, 'bag' => 'default'])
@php
    $id = $id ?? str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $error = $error ?? ($errors->getBag($bag)->first($name) ?: null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $selected = (string) ($value ?? old($name, ''));
@endphp
<div {{ $attributes->only('class')->class(['ds-field', 'has-error' => (bool) $error]) }}>
    <label for="{{ $id }}" class="ds-label">{{ $label }}@if ($optional) <span class="ds-label__opt">Optional</span>@endif</label>
    <div class="ds-control is-select">
        <select id="{{ $id }}" name="{{ $name }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes->except('class') }}>
            @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $optValue => $optLabel)<option value="{{ $optValue }}" @selected((string) $optValue === $selected)>{{ $optLabel }}</option>@endforeach
            {{ $slot }}
        </select>
        <x-ds.icon name="chevron-down" :size="18" class="ds-control__caret" />
    </div>
    @if ($hint)<p id="{{ $id }}-hint" class="ds-hint">{{ $hint }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ds-error"><x-ds.icon name="alert" :size="14" /> <span>{{ $error }}</span></p>@endif
</div>
