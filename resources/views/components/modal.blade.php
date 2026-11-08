@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'ds-dialog--sm',
    'md' => '',
    'lg' => 'ds-dialog--lg',
    'xl' => 'ds-dialog--lg',
    '2xl' => 'ds-dialog--lg',
][$maxWidth ?? '2xl'];
@endphp

{{-- Jetstream's Livewire-driven modal (wire:model on a boolean), drawn with the design system's dialog look. Alpine keeps the focus trap and Escape. --}}
<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="jm"
    style="display: none;"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    <div x-show="show" class="jm__scrim" x-on:click="show = false"
         x-transition:enter="jm-enter" x-transition:enter-start="jm-from" x-transition:enter-end="jm-to"
         x-transition:leave="jm-leave" x-transition:leave-start="jm-to" x-transition:leave-end="jm-from"></div>

    <div x-show="show" class="jm__panel {{ $maxWidth }}"
         x-trap.inert.noscroll="show"
         x-transition:enter="jm-enter" x-transition:enter-start="jm-from" x-transition:enter-end="jm-to"
         x-transition:leave="jm-leave" x-transition:leave-start="jm-to" x-transition:leave-end="jm-from">
        {{ $slot }}
    </div>
</div>
