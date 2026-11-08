{{--
  The signed-out layout. Rendered by App\View\Components\GuestLayout (props: title, description, variant; slots: header, default).
  variant 'card': an aside that says what Dot.Auction is (real figures from config only) beside one centred form card with the page's single h1.
  variant 'document': a reading page (terms, privacy, cookies) with a back link.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title, 'description' => $description])
</head>
<body class="gx-body">
    <a class="skip-link" href="#main">Skip to main content</a>
    <header class="gx-top">
        <x-ds.brand :href="url('/')" :width="104" />
        <x-ds.theme-toggle />
    </header>

    @if ($variant === 'document')
        <main id="main" class="gx-doc" tabindex="-1">
            <a href="{{ url('/') }}" class="ds-pagehead__back"><x-ds.icon name="arrow-left" :size="16" /> Back to Dot.Auction</a>
            <h1 class="gx-doc__title">{{ $title }}</h1>
            <div class="ds-prose">{{ $slot }}</div>
        </main>
    @else
        <div class="gx-split">
            <aside class="gx-aside" aria-label="About Dot.Auction">
                <p class="gx-aside__lead">Bid until the hammer falls.</p>
                <p class="gx-aside__text">Every lot on Dot.Auction has a start price, a bid step and a closing time. The sale page always says where the lot stands.</p>
                <ul class="gx-facts">
                    <li>Closing times are shown in South African time.</li>
                    <li>A bid in the last {{ config('auction.snipe_window_minutes') }} minutes moves the close out by {{ config('auction.snipe_extension_minutes') }} minutes, up to {{ config('auction.snipe_max_extensions') }} times.</li>
                    <li>A reserve stays private. Bidders only see whether it has been met.</li>
                </ul>
            </aside>
            <main id="main" class="gx-main" tabindex="-1">
                <div class="gx-card">
                    <h1 class="gx-card__title">{{ isset($header) ? strip_tags((string) $header) : $title }}</h1>
                    {{ $slot }}
                </div>
            </main>
        </div>
    @endif

    <footer class="gx-foot">
        <nav aria-label="Legal">
            <a href="{{ route('terms.show') }}">Terms</a>
            <a href="{{ route('policy.show') }}">Privacy</a>
            <a href="{{ route('cookies') }}">Cookies</a>
        </nav>
        <p>Dot.Auction is part of the Dot ecosystem.</p>
    </footer>
    <x-ds.toasts />
</body>
</html>
