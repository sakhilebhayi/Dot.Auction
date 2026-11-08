{{--
  ds.alert: an inline message. Icon shape, a short title and the sentence carry the tone; colour only reinforces it.
  Props: tone='info' (info | success | notice | danger), title=null, dismissible=false, role=null (danger and notice use "alert", the rest "status").
  Slot: the message. Errors that belong to a field go with the field instead (ds.input error).
--}}
@props(['tone' => 'info', 'title' => null, 'dismissible' => false, 'role' => null])
@php
    $icons = ['info' => 'info', 'success' => 'check-circle', 'notice' => 'pause', 'danger' => 'alert'];
    $role = $role ?? (in_array($tone, ['danger', 'notice'], true) ? 'alert' : 'status');
@endphp
<div {{ $attributes->class(['ds-alert', 'ds-alert--'.$tone]) }} role="{{ $role }}" data-ds-alert>
    <x-ds.icon :name="$icons[$tone] ?? 'info'" :size="20" class="ds-alert__icon" />
    <div class="ds-alert__body">
        @if ($title)<p class="ds-alert__title">{{ $title }}</p>@endif
        <div class="ds-alert__text">{{ $slot }}</div>
    </div>
    @if ($dismissible)<button type="button" class="ds-alert__close" data-ds-dismiss aria-label="Dismiss message"><x-ds.icon name="close" :size="16" /></button>@endif
</div>
