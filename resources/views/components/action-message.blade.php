@props(['on'])

<div x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 3000); })"
    x-show="shown"
    x-transition.opacity.duration.200ms
    style="display: none;"
    role="status"
    {{ $attributes->merge(['class' => 'jf-saved']) }}>
    <x-ds.icon name="check" :size="16" /> {{ $slot->isEmpty() ? 'Saved.' : $slot }}
</div>
