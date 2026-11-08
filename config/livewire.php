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
    // The only Livewire upload in the app is the profile photo (jpg or png, up to 1 MB, validated again on save). Livewire's own
    // default accepts any file up to 12 MB into a temporary folder; this narrows it to the same rule and rate-limits it.
    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:1024'],
        'middleware' => 'throttle:20,1',
    ],

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => 'var(--c-ink)',
    ],
];
