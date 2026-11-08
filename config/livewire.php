<?php

/*
| Only the keys this app changes; Livewire merges the rest from its own defaults.
|
| navigate.progress_bar_color: the app never uses wire:navigate, but Livewire still ships the progress bar's styles. Its colour is a
| design token here instead of Livewire's #2299dd (the bar's zero-offset glow was flagged by the design detector).
| show_progress_bar stays true on purpose: with it false Livewire 3.6 runs Alpine.navigate.disableProgressBar() before Alpine exists
| and throws "Alpine is not defined" on every page.
*/
return [
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => 'var(--c-ink)',
    ],
];
