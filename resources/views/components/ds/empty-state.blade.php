{{--
  ds.empty-state: what belongs here and the one action that fills it. Keep the frame of the region it replaces.
  Props: title (required), icon='gavel', body=null, level=3 (heading level; use 2 when the empty state is the first thing under the page's h1). Slot: the action (usually one ds.button).
--}}
@props(['title', 'icon' => 'gavel', 'body' => null, 'level' => 3])
<div {{ $attributes->class('ds-empty') }}>
    <x-ds.icon :name="$icon" :size="28" class="ds-empty__icon" />
    <h{{ $level }} class="ds-empty__title">{{ $title }}</h{{ $level }}>
    @if ($body)<p class="ds-empty__body">{{ $body }}</p>@endif
    @if (trim((string) $slot) !== '')<div class="ds-empty__action">{{ $slot }}</div>@endif
</div>
