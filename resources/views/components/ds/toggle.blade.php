{{--
  ds.toggle: an on/off switch (a checkbox with role=switch). Use for settings that apply immediately; use ds.checkbox for choices submitted with a form.
  Props: label (required), name (required), id=null, checked=false, hint=null. The state is also written in words ("On" / "Off") beside the switch.
--}}
@props(['label', 'name', 'id' => null, 'checked' => false, 'hint' => null])
@php $id = $id ?? str_replace(['[', ']', '.'], ['-', '', '-'], $name); @endphp
<div {{ $attributes->only('class')->class('ds-field') }}>
    <label class="ds-toggle" for="{{ $id }}">
        <input type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" @checked($checked) @if ($hint) aria-describedby="{{ $id }}-hint" @endif {{ $attributes->except('class') }}>
        <span class="ds-toggle__track" aria-hidden="true"><span class="ds-toggle__knob"></span></span>
        <span class="ds-toggle__text">{{ $label }}</span>
        <span class="ds-toggle__state" aria-hidden="true"><span class="on">On</span><span class="off">Off</span></span>
    </label>
    @if ($hint)<p id="{{ $id }}-hint" class="ds-hint ds-hint--indent">{{ $hint }}</p>@endif
</div>
