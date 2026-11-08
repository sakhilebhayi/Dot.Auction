{{--
  ds.nav-item: one destination of the app navigation (rail, phone bar and phone menu share it).
  Props: href (required), icon (required), active=false, badge=null (a count), variant='rail' (rail | bar | row), accent=false (the gold primary action in the phone bar).
  The current page carries aria-current="page" and a heavier mark and bold label, not colour alone.
--}}
@props(['href', 'icon', 'active' => false, 'badge' => null, 'variant' => 'rail', 'accent' => false])
<a href="{{ $href }}" {{ $attributes->class(['ds-nav', 'ds-nav--'.$variant, 'is-active' => $active, 'is-accent' => $accent]) }} @if ($active) aria-current="page" @endif @if ($href === '#') data-pending-route @endif>
    <x-ds.icon :name="$icon" :size="$variant === 'bar' ? 22 : 20" />
    <span class="ds-nav__label">{{ $slot }}</span>
    @if ($badge)<span class="ds-nav__badge">{{ $badge }}</span>@endif
</a>
