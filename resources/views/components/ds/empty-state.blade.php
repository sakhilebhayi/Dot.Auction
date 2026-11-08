{{--
  ds.empty-state: what belongs here and the one action that fills it. Keep the frame of the region it replaces.
  Props: title (required), icon='gavel', body=null. Slot: the action (usually one ds.button).
--}}
@props(['title', 'icon' => 'gavel', 'body' => null])
<div {{ $attributes->class('ds-empty') }}>
    <x-ds.icon :name="$icon" :size="28" class="ds-empty__icon" />
    <h3 class="ds-empty__title">{{ $title }}</h3>
    @if ($body)<p class="ds-empty__body">{{ $body }}</p>@endif
    @if (trim((string) $slot) !== '')<div class="ds-empty__action">{{ $slot }}</div>@endif
</div>
