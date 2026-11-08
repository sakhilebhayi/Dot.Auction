{{--
  ds.theme-toggle: switches Chalk (light) and Graphite (dark). Writes data-theme and the .dark class on <html> and stores the choice in localStorage ('dot-theme').
  With no stored choice the theme follows the system. Works on every page that loads resources/js/app.js; the head script in the layouts applies the choice before first paint.
  Props: variant='icon' (44px icon button) | 'menu' (a full row with the word, for the phone menu).
--}}
@props(['variant' => 'icon'])
@if ($variant === 'menu')
    <button type="button" class="ds-menurow" data-theme-toggle aria-label="Switch theme">
        <x-ds.icon name="moon" :size="20" class="when-light" /><x-ds.icon name="sun" :size="20" class="when-dark" />
        <span class="when-light">Dark theme</span><span class="when-dark">Light theme</span>
    </button>
@else
    <button type="button" {{ $attributes->class('ds-iconbtn') }} data-theme-toggle title="Switch theme" aria-label="Switch theme">
        <x-ds.icon name="moon" :size="20" class="when-light" /><x-ds.icon name="sun" :size="20" class="when-dark" />
    </button>
@endif
