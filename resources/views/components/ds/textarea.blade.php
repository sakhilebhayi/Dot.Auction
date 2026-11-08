{{--
  ds.textarea: as ds.input, for longer text. Props: label, name, id=null, value=null (old(name)), hint=null, error=null, optional=false, rows=5, bag='default'.
  Attributes pass to the <textarea>; class goes to the wrapper.
--}}
@props(['label', 'name', 'id' => null, 'value' => null, 'hint' => null, 'error' => null, 'optional' => false, 'rows' => 5, 'bag' => 'default'])
@php
    $id = $id ?? str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $error = $error ?? ($errors->getBag($bag)->first($name) ?: null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div {{ $attributes->only('class')->class(['ds-field', 'has-error' => (bool) $error]) }}>
    <label for="{{ $id }}" class="ds-label">{{ $label }}@if ($optional) <span class="ds-label__opt">Optional</span>@endif</label>
    <div class="ds-control is-area">
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes->except('class') }}>{{ $value ?? old($name) }}</textarea>
    </div>
    @if ($hint)<p id="{{ $id }}-hint" class="ds-hint">{{ $hint }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ds-error"><x-ds.icon name="alert" :size="14" /> <span>{{ $error }}</span></p>@endif
</div>
