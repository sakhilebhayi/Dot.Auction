{{--
  ds.badge: a small label or count. Words and numbers only; a status of a lot uses ds.status instead.
  Props: tone='neutral' (neutral | ink | live | urgent | notice | scheduled), icon=null, size='md' (sm | md).
--}}
@props(['tone' => 'neutral', 'icon' => null, 'size' => 'md'])
<span {{ $attributes->class(['ds-badge', 'ds-badge--'.$tone, 'ds-badge--'.$size]) }}>@if ($icon)<x-ds.icon :name="$icon" :size="12" />@endif<span>{{ $slot }}</span></span>
