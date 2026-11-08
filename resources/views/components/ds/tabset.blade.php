{{--
  ds.tabset + ds.tab-panel: in-page tabs for content that is already on the page (role=tablist; arrow keys move, Home/End jump).
  Props: tabs (required): ['key' => 'Label', ...] in order, label (required), active=null (first by default).
  Slot: one <x-ds.tab-panel name="key"> per tab. Without scripting every panel shows, stacked, each under its own heading.
--}}
@props(['tabs', 'label', 'active' => null])
@php $active = $active ?? array_key_first($tabs); $sid = 'tabs-'.substr(md5($label.implode('|', array_keys($tabs))), 0, 6); @endphp
<div {{ $attributes->class('ds-tabset') }} data-ds-tabset>
    <div class="ds-tabs ds-tabs--set" role="tablist" aria-label="{{ $label }}">
        @foreach ($tabs as $key => $text)
            <button type="button" role="tab" class="ds-tabs__tab {{ $key === $active ? 'is-current' : '' }}" id="{{ $sid }}-tab-{{ $key }}" aria-controls="{{ $sid }}-panel-{{ $key }}" aria-selected="{{ $key === $active ? 'true' : 'false' }}" tabindex="{{ $key === $active ? '0' : '-1' }}" data-tab="{{ $key }}">{{ $text }}</button>
        @endforeach
    </div>
    <div class="ds-tabset__panels" data-sid="{{ $sid }}">{{ $slot }}</div>
</div>
