@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1', 'dropdownClasses' => ''])

@php
$alignmentClasses = match ($align) {
    'left' => 'ds-menu--start',
    'top' => 'ds-menu--end',
    'none', 'false' => '',
    default => 'ds-menu--end',
};
@endphp

{{-- Jetstream's Alpine dropdown, kept for any Jetstream view that still uses it. New work uses <x-ds.dropdown>. --}}
<div class="ds-dropdown" x-data="{ open: false }" @click.away="open = false" @close.stop="open = false">
    <div @click="open = ! open">{{ $trigger }}</div>
    <div x-show="open" class="ds-menu {{ $alignmentClasses }} {{ $dropdownClasses }}" style="display: none;" @click="open = false">
        {{ $content }}
    </div>
</div>
