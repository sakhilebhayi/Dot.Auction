{{--
  ds.field: label + hint + error wiring around ANY control you write yourself (a custom picker, a file input).
  Props: label (required), name (required, the error key), id=null (defaults to name), hint=null, error=null (default: the first validation error for name),
         optional=false (prints "Optional"; mark optional fields, not required ones), bag='default'.
  The control inside must carry id="{id}" and aria-describedby="{id}-hint {id}-error" (only the ids that exist) and aria-invalid when there is an error.
  Use ds.input, ds.textarea, ds.select, ds.checkbox, ds.toggle, ds.radio-group first: they already do this.
--}}
@props(['label', 'name', 'id' => null, 'hint' => null, 'error' => null, 'optional' => false, 'bag' => 'default'])
@php
    $id = $id ?? str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $error = $error ?? ($errors->getBag($bag)->first($name) ?: null);
@endphp
<div {{ $attributes->class(['ds-field', 'has-error' => (bool) $error]) }}>
    <label for="{{ $id }}" class="ds-label">{{ $label }}@if ($optional) <span class="ds-label__opt">Optional</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p id="{{ $id }}-hint" class="ds-hint">{{ $hint }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ds-error"><x-ds.icon name="alert" :size="14" /> <span>{{ $error }}</span></p>@endif
</div>
