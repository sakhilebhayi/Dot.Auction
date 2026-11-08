{{--
  ds.checkbox: a checkbox with its label (the whole row is the 44px target). Props: label (required), name (required), id=null, value='1', checked=false (old(name) wins),
  hint=null, error=null, bag='default'. Attributes pass to the <input>; class goes to the wrapper.
--}}
@props(['label', 'name', 'id' => null, 'value' => '1', 'checked' => false, 'hint' => null, 'error' => null, 'bag' => 'default'])
@php
    $id = $id ?? str_replace(['[', ']', '.'], ['-', '', '-'], $name).'-'.$value;
    $error = $error ?? ($errors->getBag($bag)->first($name) ?: null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $isChecked = old($name) !== null ? (string) old($name) === (string) $value : (bool) $checked;
@endphp
<div {{ $attributes->only('class')->class(['ds-field', 'has-error' => (bool) $error]) }}>
    <label class="ds-check" for="{{ $id }}">
        <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked($isChecked) @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes->except('class') }}>
        <span class="ds-check__box" aria-hidden="true"><x-ds.icon name="check" :size="14" /></span>
        <span class="ds-check__text">{{ $label }}</span>
    </label>
    @if ($hint)<p id="{{ $id }}-hint" class="ds-hint ds-hint--indent">{{ $hint }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ds-error"><x-ds.icon name="alert" :size="14" /> <span>{{ $error }}</span></p>@endif
</div>
