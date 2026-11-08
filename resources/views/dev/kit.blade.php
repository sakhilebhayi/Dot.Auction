{{--
  /dev/kit: every design-system component, light and dark side by side. Local and testing only (registered in the "dev" block of routes/web.php).
  Each section renders the same partial twice, once inside data-scheme="light" and once inside data-scheme="dark", so tokens resolve per pane.
--}}
<!DOCTYPE html>
<html lang="en-ZA">
<head>
    @include('layouts.partials.head', ['title' => 'Component kit', 'robots' => 'noindex', 'vite' => ['resources/css/app.css', 'resources/css/kit.css', 'resources/js/app.js']])
</head>
<body>
    <a class="skip-link" href="#main">Skip to main content</a>
    <header class="kit-top">
        <x-ds.brand :width="104" :href="url('/')" />
        <p class="kit-top__note">Dot.Auction component kit. Local only.</p>
        <x-ds.theme-toggle />
    </header>
    <main id="main" class="kit-main" tabindex="-1">
        <h1 class="kit-h1">Component kit</h1>
        <p class="kit-lead">Each block shows the real component in Chalk (light) and Graphite (dark). The theme button above changes the whole page; the panes pin their own theme.</p>

        @php
            $groups = [
                'Foundations' => 'dev.kit.foundations',
                'Status vocabulary' => 'dev.kit.status',
                'Clock, rail and ladder' => 'dev.kit.auction',
                'Lot cards' => 'dev.kit.lots',
                'Buttons and menus' => 'dev.kit.actions',
                'Forms' => 'dev.kit.forms',
                'Data and structure' => 'dev.kit.data',
                'Feedback and overlays' => 'dev.kit.feedback',
            ];
        @endphp

        <nav class="kit-toc" aria-label="Kit sections">
            @foreach ($groups as $label => $view)
                <a href="#{{ \Illuminate\Support\Str::slug($label) }}">{{ $label }}</a>
            @endforeach
        </nav>

        @foreach ($groups as $label => $view)
            <section class="kit-group" id="{{ \Illuminate\Support\Str::slug($label) }}" aria-labelledby="h-{{ \Illuminate\Support\Str::slug($label) }}">
                <h2 id="h-{{ \Illuminate\Support\Str::slug($label) }}" class="kit-h2">{{ $label }}</h2>
                <div class="kit-panes">
                    @foreach (['light', 'dark'] as $scheme)
                        <div class="kit-pane" data-scheme="{{ $scheme }}">
                            <p class="kit-pane__tag">{{ $scheme === 'light' ? 'Chalk' : 'Graphite' }}</p>
                            @include($view, ['scheme' => $scheme, 'lots' => $lots, 'now' => $now])
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section class="kit-group" aria-labelledby="h-icons">
            <h2 id="h-icons" class="kit-h2">Icons</h2>
            <ul class="kit-icons">
                @foreach (\App\Support\Icons::names() as $name)
                    <li><x-ds.icon :name="$name" :size="22" /><span>{{ $name }}</span></li>
                @endforeach
            </ul>
        </section>
    </main>
    <x-ds.toasts />
</body>
</html>
