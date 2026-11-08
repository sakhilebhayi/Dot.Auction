{{--
  ds.radio-group: a fieldset of radios. Props: legend (required), name (required), options=[] (value => label, or value => [label, hint]), value=null (selected; old(name)),
  hint=null, error=null, inline=false, bag='default'. Attributes (wire:model) are put on each radio.
--}}
@props(['legend', 'name', 'options' => [], 'value' => null, 'hint' => null, 'error' => null, 'inline' => false, 'bag' => 'default'])
@php
    $gid = str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $error = $error ?? ($errors->getBag($bag)->first($name) ?: null);
    $selected = (string) ($value ?? old($name, ''));
@endphp
<fieldset {{ $attributes->only('class')->class(['ds-field', 'ds-radios', 'is-inline' => $inline, 'has-error' => (bool) $error]) }} @if ($error) aria-describedby="{{ $gid }}-error" @endif>
    <legend class="ds-label">{{ $legend }}</legend>
    @if ($hint)<p class="ds-hint ds-hint--top">{{ $hint }}</p>@endif
    @foreach ($options as $optValue => $opt)
        @php [$optLabel, $optHint] = is_array($opt) ? [$opt[0], $opt[1] ?? null] : [$opt, null]; $rid = $gid.'-'.$optValue; @endphp
        <label class="ds-check ds-check--radio" for="{{ $rid }}">
            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $optValue }}" @checked((string) $optValue === $selected) {{ $attributes->except('class') }}>
            <span class="ds-check__box" aria-hidden="true"></span>
            <span class="ds-check__text">{{ $optLabel }}@if ($optHint)<span class="ds-check__hint">{{ $optHint }}</span>@endif</span>
        </label>
    @endforeach
    @if ($error)<p id="{{ $gid }}-error" class="ds-error"><x-ds.icon name="alert" :size="14" /> <span>{{ $error }}</span></p>@endif
</fieldset>
