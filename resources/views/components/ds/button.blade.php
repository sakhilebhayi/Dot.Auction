{{--
  ds.button: the one button.
  Props: variant='primary' (gold gavel, the page's main action) | 'quiet' (outlined) | 'danger' (destructive) | 'link' (inline, looks like a link),
         size='md' (sm 36px, md 44px, lg 52px), href=null (renders <a>), type='button' (use "submit" in forms), icon=null, iconRight=null,
         loading=false (shows a spinner, disables, sets aria-busy), block=false (full width), disabled=false.
  Slot: the label (always a word; icon-only buttons use ds.icon-button).
  Extra attributes pass through: wire:click, data-dialog-open="id", x-on:click, form="id".
  Forms: a submit button gets data-ds-lock, so a double click cannot send twice; Livewire's wire:loading attributes also work.
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'iconRight' => null, 'loading' => false, 'block' => false, 'disabled' => false])
@php
    $classes = ['ds-btn', 'ds-btn--'.$variant, 'ds-btn--'.$size, 'ds-btn--block' => $block, 'is-loading' => $loading];
    $iconSize = $size === 'sm' ? 16 : 18;
@endphp
@if ($href && ! $disabled && ! $loading)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ds.icon :name="$icon" :size="$iconSize" />@endif
        <span class="ds-btn__label">{{ $slot }}</span>
        @if ($iconRight)<x-ds.icon :name="$iconRight" :size="$iconSize" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @disabled($disabled || $loading) @if ($loading) aria-busy="true" @endif @if ($type === 'submit') data-ds-lock @endif>
        @if ($loading)<x-ds.icon name="loader" :size="$iconSize" spin />@elseif ($icon)<x-ds.icon :name="$icon" :size="$iconSize" />@endif
        <span class="ds-btn__label">{{ $slot }}</span>
        @if ($iconRight && ! $loading)<x-ds.icon :name="$iconRight" :size="$iconSize" />@endif
    </button>
@endif
