{{--
  ds.dropdown: a menu button. Vanilla behaviour in resources/js/ds.js: click or Enter/Space/ArrowDown opens, ArrowUp/Down moves, Home/End jump, Escape or an outside click closes and returns focus.
  Props: label (required; the trigger's accessible name when it shows only an icon or an avatar), align='end' (start | end), id=null.
  Slots: trigger (the visible content of the trigger button: a word, a ds.avatar, an icon), default (menu items: ds.dropdown-item, or <hr class="ds-menu__rule">).
  Extra attributes go to the wrapper.
--}}
@props(['label', 'align' => 'end', 'id' => null])
@php $id = $id ?? 'menu-'.substr(md5($label.json_encode($attributes->getAttributes())), 0, 8); @endphp
<div {{ $attributes->class('ds-dropdown') }} data-ds-dropdown>
    <button type="button" class="ds-dropdown__trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="{{ $id }}" aria-label="{{ $label }}" data-dropdown-trigger>{{ $trigger }}</button>
    <div id="{{ $id }}" class="ds-menu ds-menu--{{ $align }}" role="menu" aria-label="{{ $label }}" hidden>{{ $slot }}</div>
</div>
