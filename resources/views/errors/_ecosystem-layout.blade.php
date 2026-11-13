{{--
  Standalone error layout (403, 404, 419, 429, 500, 503). It must work when the database, the session and the cache are down, so:
  no @auth (the session may not be readable), no Livewire, no query. The signed-in links are chosen by trying the session once inside rescue().
  Styles come from resources/css/errors.css when the build manifest lists it; the inline fallback keeps the page legible without it.
  Sections: icon (an icon name), title, message, actions, after (optional, below the actions). $code is the HTTP status.
  $discover = false leaves out the list of other platforms.
--}}
@php
    $manifest = public_path('build/manifest.json');
    $built = file_exists($manifest) ? (json_decode((string) file_get_contents($manifest), true) ?: []) : [];
    $entries = array_values(array_filter(['resources/css/errors.css', 'resources/js/ds.js'], fn ($e) => isset($built[$e])));

    $nonce = function_exists('csp_nonce') ? csp_nonce() : '';

    $discover = ($discover ?? true) === false ? [] : collect(config('ecosystem.platforms', []))
        ->reject(fn ($p) => ($p['name'] ?? null) === 'Dot.Auction')
        ->reject(fn ($p) => ($p['active'] ?? true) === false)
        ->reject(fn ($p, $k) => ($p['internal'] ?? false) === true || in_array($k, ['memory', 'brain', 'dopemine'], true))
        ->values()->all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title>{{ $code }} @yield('title') | Dot.Auction</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="preload" as="font" type="font/woff2" href="{{ asset('fonts/archivo-var-latin.woff2') }}" crossorigin>
    <style @if ($nonce) nonce="{{ $nonce }}" @endif>body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}main{max-width:40rem;padding:2rem 1rem}a{color:inherit}</style>
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>
        (function () { try { var s = localStorage.getItem('dot-theme'); var m = (s === 'dark' || s === 'light') ? s : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); document.documentElement.dataset.theme = m; document.documentElement.classList.toggle('dark', m === 'dark'); } catch (e) {} })();
    </script>
    @foreach ($entries as $entry)
        @if (str_ends_with($entry, '.css'))<link rel="stylesheet" href="{{ asset('build/'.$built[$entry]['file']) }}">@else<script type="module" src="{{ asset('build/'.$built[$entry]['file']) }}"></script>@endif
    @endforeach
</head>
<body class="er-body">
    <a class="skip-link" href="#main">Skip to main content</a>
    <header class="er-top">
        <x-ds.brand :href="url('/')" :width="104" />
        <x-ds.theme-toggle />
    </header>

    <main id="main" class="er-main" tabindex="-1">
        <p class="er-code"><span class="sr-only">Error </span>{{ $code }}</p>
        <x-ds.icon :name="trim($__env->yieldContent('icon', 'alert'))" :size="32" class="er-icon" />
        <h1 class="er-title">@yield('title')</h1>
        <p class="er-message">@yield('message')</p>
        <div class="er-actions">
            @yield('actions')
        </div>
        @yield('after')

        @if (! empty($discover))
            <section class="er-discover" aria-labelledby="er-discover">
                <h2 id="er-discover">While you are here, the rest of the Dot Ecosystem</h2>
                <ul>
                    @foreach ($discover as $platform)
                        <li><a href="{{ $platform['url'] }}">{{ $platform['name'] }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>

    <footer class="gx-foot er-foot">
        <p>Dot.Auction is part of the Dot ecosystem.</p>
        @if (Route::has('contact'))<a href="{{ route('contact') }}">Contact support</a>@endif
    </footer>
</body>
</html>
