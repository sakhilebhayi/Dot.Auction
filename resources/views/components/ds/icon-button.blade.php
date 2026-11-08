{{--
  ds.icon-button: a square 44px button with only an icon. It always has an accessible name.
  Props: icon (required), label (required, the accessible name; also shown as the tooltip), href=null, type='button', badge=null (a count shown on the corner), size=20.
  A badge adds ", N unread" style text through $badgeLabel (default: the number), so the visible number is part of the name.
--}}
@props(['icon', 'label', 'href' => null, 'type' => 'button', 'badge' => null, 'badgeLabel' => null, 'size' => 20])
@php $tag = $href ? 'a' : 'button'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif title="{{ $label }}" {{ $attributes->class('ds-iconbtn') }}>
    <x-ds.icon :name="$icon" :size="$size" />
    <span class="sr-only">{{ $label }}@if ($badge), {{ $badgeLabel ?? $badge }}@endif</span>
    @if ($badge)<span class="ds-iconbtn__badge" aria-hidden="true">{{ $badge }}</span>@endif
</{{ $tag }}>
