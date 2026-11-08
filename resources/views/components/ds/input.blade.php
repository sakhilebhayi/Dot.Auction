{{--
  ds.input: label, control, hint and error in one, with ids wired for assistive tech.
  Props: label (required), name (required), type='text', id=null, value=null (falls back to old(name)), hint=null, error=null (auto from the validation bag),
         optional=false, prefix=null (text before the value, e.g. the currency symbol), suffix=null, bag='default', money=false (decimal keyboard, tabular figures, prefix = currency symbol).
  Attributes pass to the <input>: wire:model, required, autocomplete, min, max, step, data-ds-bid-input. Class goes to the wrapper.
  Touch: the control is 44px tall (48px on phones) and 16px type, so iOS never zooms.
--}}
@props(['label', 'name', 'type' => 'text', 'id' => null, 'value' => null, 'hint' => null, 'error' => null, 'optional' => false, 'prefix' => null, 'suffix' => null, 'bag' => 'default', 'money' => false])
@php
    $id = $id ?? str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $error = $error ?? ($errors->getBag($bag)->first($name) ?: null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    if ($money) { $prefix = $prefix ?? \App\Support\Display::symbol(); }
    $val = $type === 'password' ? null : ($value ?? old($name));
@endphp
<div {{ $attributes->only('class')->class(['ds-field', 'has-error' => (bool) $error]) }}>
    <label for="{{ $id }}" class="ds-label">{{ $label }}@if ($optional) <span class="ds-label__opt">Optional</span>@endif</label>
    <div class="ds-control @if ($prefix) has-prefix @endif @if ($suffix) has-suffix @endif @if ($money) is-money @endif">
        @if ($prefix)<span class="ds-affix ds-affix--pre" aria-hidden="true">{{ $prefix }}</span>@endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if (! is_null($val)) value="{{ $val }}" @endif
            @if ($money) inputmode="decimal" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif
            {{ $attributes->except('class') }}>
        @if ($suffix)<span class="ds-affix ds-affix--post" aria-hidden="true">{{ $suffix }}</span>@endif
    </div>
    @if ($hint)<p id="{{ $id }}-hint" class="ds-hint">{{ $hint }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="ds-error"><x-ds.icon name="alert" :size="14" /> <span>{{ $error }}</span></p>@endif
</div>
