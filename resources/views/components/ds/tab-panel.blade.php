{{-- ds.tab-panel: one panel of ds.tabset. Props: name (required, the key used in the tabset's tabs array), heading=null (shown only when scripting is off). --}}
@props(['name', 'heading' => null])
<div {{ $attributes->class('ds-tabpanel') }} role="tabpanel" data-panel="{{ $name }}" tabindex="0">
    @if ($heading)<h3 class="ds-tabpanel__nojs-heading">{{ $heading }}</h3>@endif
    {{ $slot }}
</div>
