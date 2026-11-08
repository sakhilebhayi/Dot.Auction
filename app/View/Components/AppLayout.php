<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The signed-in shell (resources/views/layouts/app.blade.php).
 *
 * Usage: <x-app-layout title="Browse lots" description="..." width="wide">
 *            <x-slot name="header">Browse lots</x-slot>        the page's one h1 (omit to use the title as a visually hidden h1)
 *            <x-slot name="meta">...status chips...</x-slot>    optional, above the h1
 *            <x-slot name="actions">...buttons...</x-slot>      optional, right of the h1
 *            ...page body...
 *        </x-app-layout>
 *
 * title        unique <title> text (the app name is appended); also the visually hidden h1 when no header slot is given
 * description  meta description, optional
 * width        'default' (80rem) | 'narrow' (46rem, forms and reading) | 'wide' (96rem) | 'full' (edge to edge)
 * back/backLabel  a link above the h1 to the parent page
 * titleVisible false keeps the h1 but hides it (pages that still draw their own heading; the UI agents remove this as they rebuild)
 */
class AppLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public string $width = 'default',
        public ?string $back = null,
        public string $backLabel = 'Back',
        public bool $titleVisible = true,
    ) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
