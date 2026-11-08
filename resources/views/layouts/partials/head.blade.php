{{--
  Shared <head> content for every layout (app, guest, welcome, errors).
  Variables: $title (string|null, the page's own name; the app name is appended), $description (string|null), $vite (list of entry files, default app.css + app.js),
             $robots (string|null, e.g. "noindex" for signed-in pages).
  The inline script applies the saved theme before first paint so there is no flash; everything else about the theme lives in resources/js/ds.js.
--}}
@php
    $appName = config('app.name', 'Dot.Auction');
    $fullTitle = ! empty($title) ? $title.' | '.$appName : $appName;
    $entries = $vite ?? ['resources/css/app.css', 'resources/js/app.js'];
    $nonce = function_exists('csp_nonce') ? csp_nonce() : '';
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#eef0ef" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1c2120" media="(prefers-color-scheme: dark)">
@if (! empty($description))<meta name="description" content="{{ $description }}">@endif
@if (! empty($robots))<meta name="robots" content="{{ $robots }}">@endif
<title>{{ $fullTitle }}</title>
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="preload" as="font" type="font/woff2" href="{{ asset('fonts/archivo-var-latin.woff2') }}" crossorigin>
<script @if ($nonce) nonce="{{ $nonce }}" @endif>
    (function () {
        try {
            var saved = localStorage.getItem('dot-theme');
            var mode = (saved === 'dark' || saved === 'light') ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            var root = document.documentElement;
            root.dataset.theme = mode;
            root.classList.toggle('dark', mode === 'dark');
        } catch (e) {}
    })();
</script>
@vite($entries)
