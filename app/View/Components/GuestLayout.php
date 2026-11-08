<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The signed-out layout (resources/views/layouts/guest.blade.php): sign-in, password and verification screens, and the legal pages.
 *
 * title        unique <title> text and, unless a header slot is given, the card's h1
 * description  meta description, optional
 * variant      'card' (a narrow centred form, default) | 'document' (a reading page: terms, privacy, cookies)
 */
class GuestLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public string $variant = 'card',
    ) {}

    public function render(): View
    {
        return view('layouts.guest');
    }
}
