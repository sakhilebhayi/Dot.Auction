<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts: same family set as welcome.blade.php, for a consistent brand voice on guest pages -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,900&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        {{-- Single fixed brand theme (ink/paper/gold/red), matching welcome.blade.php. Auth/guest
             pages don't get a dark-mode toggle — that pre-existing infrastructure has been removed
             from this layout so the guest scope stays on one deliberate look, same as the marketing page. --}}
        <style>
            :root {
                --ink: #17110f;
                --ink-soft: #221a17;
                --paper: #f4ece0;
                --stone: #c7b39c;
                --gold: #f1c62e;
                --gold-soft: #f8da68;
                --red: #d71016;
                --red-soft: #ea4348;
                --line: rgba(244, 236, 224, 0.12);
                --font-display: 'Fraunces', Georgia, serif;
                --font-body: 'Work Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
            }
            html { background: var(--ink); }
            body { font-family: var(--font-body); background: var(--ink); color: var(--stone); }
            .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms cubic-bezier(0.23, 1, 0.32, 1); }
            .press:active { transform: scale(0.97); }
        </style>
    </head>
    <body class="antialiased">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
