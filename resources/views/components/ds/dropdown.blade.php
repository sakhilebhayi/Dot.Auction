{{--
  ds.dropdown: a menu button. Vanilla behaviour in resources/js/ds.js: click or Enter/Space/ArrowDown opens, ArrowUp/Down moves, Home/End jump, Escape or an outside click closes and returns focus.
  Props: label (required; names the menu, and the trigger when iconOnly), align='end' (start | end), id=null, iconOnly=false (true when the trigger shows no words: then label is its aria-label; a trigger with visible words is named by them, never by a different aria-label).
  Slots: trigger (the visible content of the trigger button: a word, a ds.avatar, an icon), default (menu items: ds.dropdown-item, or <hr class="ds-menu__rule">).
  Extra attributes go to the wrapper.
--}}
@props(['label', 'align' => 'end', 'id' => null, 'iconOnly' => false])
@php $id = $id ?? 'menu-'.substr(md5($label.json_encode($attributes->getAttributes())), 0, 8); @endphp
<div {{ $attributes->class('ds-dropdown') }} data-ds-dropdown>
    <button type="button" class="ds-dropdown__trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="{{ $id }}" @if ($iconOnly) aria-label="{{ $label }}" @endif data-dropdown-trigger>{{ $trigger }}</button>
    <div id="{{ $id }}" class="ds-menu ds-menu--{{ $align }}" role="menu" aria-label="{{ $label }}" hidden>{{ $slot }}</div>
</div>
