{{--
  The signed-in shell: a rail on wide screens, a top bar and a bottom bar on phones. See DESIGN.md "Shell".
  Rendered by App\View\Components\AppLayout (props: title, description, width, back, backLabel, titleVisible; slots: header, meta, actions, topbar).
  Landmarks: skip link, banner (top bar), navigation (rail or phone bar), main (one h1). Slots a UI agent may fill:
    $header   the page's h1 text      $meta     chips above the h1      $actions   buttons beside the h1      $topbar   extra controls in the top bar
  The notification bell is the Livewire component <livewire:notification-bell /> (its content belongs to the discover workstream).
--}}
@php
    use App\Services\Paystack\PaystackConfig;
    $payMode = rescue(fn () => app(PaystackConfig::class)->mode(), null, false);
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title, 'description' => $description, 'robots' => 'noindex'])
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="sh-body">
    <a class="skip-link" href="#main">Skip to main content</a>
    <x-banner />

    <div class="sh">
        <nav class="sh-side" aria-label="Main">
            <x-ds.brand :href="route('dashboard')" :width="124" class="sh-side__brand" />
            <div class="sh-side__list">
                @include('layouts.partials.nav-items', ['variant' => 'rail'])
            </div>
            <p class="sh-side__foot">Times shown in {{ config('auction.display_timezone', 'Africa/Johannesburg') === 'Africa/Johannesburg' ? 'South African time' : config('auction.display_timezone') }}</p>
        </nav>

        <div class="sh-col">
            <header class="sh-top">
                <x-ds.brand variant="mark" :width="52" :href="route('dashboard')" class="sh-top__mark" />
                <form class="sh-search" method="GET" action="{{ route('auctions.index') }}" role="search">
                    {{-- On Browse this is the page's search from 64rem up, so it keeps the tab, category and sort the visitor has chosen. --}}
                    @if (request()->routeIs('auctions.index'))
                        @foreach (['state', 'category', 'sort'] as $keep)
                            @if (is_string(request()->query($keep)) && request()->query($keep) !== '')<input type="hidden" name="{{ $keep }}" value="{{ request()->query($keep) }}">@endif
                        @endforeach
                    @endif
                    <label for="shell-search" class="sr-only">Search lots</label>
                    <x-ds.icon name="search" :size="18" class="sh-search__icon" />
                    <input id="shell-search" type="search" name="q" value="{{ request()->routeIs('auctions.index') && is_string(request()->query('q')) ? request()->query('q') : '' }}" placeholder="Search lots" autocomplete="off">
                </form>
                <div class="sh-top__tools">
                    @isset($topbar){{ $topbar }}@endisset
                    @if ($payMode === 'test')
                        <x-ds.badge tone="notice" title="Paystack test keys are in use: no real money moves">Test mode</x-ds.badge>
                    @elseif ($payMode === 'live')
                        <x-ds.badge tone="urgent" title="Real money moves">Live mode</x-ds.badge>
                    @endif
                    <livewire:notification-bell />
                    <x-ds.theme-toggle />
                    <div class="sh-top__user">@include('layouts.partials.user-menu')</div>
                </div>
            </header>

            <main id="main" class="sh-main" tabindex="-1">
                <div class="sh-page sh-page--{{ $width }}">
                    @php
                        // A header slot arrives already escaped (its {{ }} ran once), and x-ds.page-header escapes its title prop again. Drop any
                        // markup, decode the slot's entities once, and let the component do the one escape: "ship's" must not read "ship&#039;s".
                        $headTitle = isset($header) && trim((string) $header) !== ''
                            ? html_entity_decode(strip_tags((string) $header), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                            : $title;
                    @endphp
                    @if ($headTitle)
                        <x-ds.page-header :title="$headTitle" :back="$back" :back-label="$backLabel" :meta="$meta ?? null" :actions="$actions ?? null" :hidden="! $titleVisible" />
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>

        <nav class="sh-bar" aria-label="Main">
            {{-- Buying is the core of the bar: Home, Browse, My bids, Watching. Selling sits in More ("List a lot" first) and on Home and My lots; the bar has no primary action of its own. --}}
            <x-ds.nav-item variant="bar" :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Home</x-ds.nav-item>
            <x-ds.nav-item variant="bar" :href="route('auctions.index')" icon="browse" :active="request()->routeIs('auctions.*')">Browse</x-ds.nav-item>
            <x-ds.nav-item variant="bar" :href="auction_route('buyer.bids')" icon="bids" :active="request()->routeIs('buyer.bids')">My bids</x-ds.nav-item>
            <x-ds.nav-item variant="bar" :href="auction_route('buyer.watchlist')" icon="bookmark" :active="request()->routeIs('buyer.watchlist')">Watching</x-ds.nav-item>
            <button type="button" class="ds-nav ds-nav--bar" data-dialog-open="shell-menu"><x-ds.icon name="menu" :size="22" /><span class="ds-nav__label">More</span></button>
        </nav>
    </div>

    <x-ds.dialog id="shell-menu" title="Menu" size="sm" class="sh-sheet">
        <nav aria-label="All pages" class="sh-sheet__nav">
            @include('layouts.partials.nav-items', ['variant' => 'row'])
        </nav>
        <hr class="ds-menu__rule">
        <div class="sh-sheet__nav">
            <a class="ds-menurow" href="{{ route('profile.show') }}"><x-ds.icon name="user" :size="20" /> Profile and security</a>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->currentTeam)
                <a class="ds-menurow" href="{{ route('teams.show', $user->currentTeam->id) }}"><x-ds.icon name="users" :size="20" /> Team: {{ $user->currentTeam->name }}</a>
            @endif
            <x-ds.theme-toggle variant="menu" />
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="ds-menurow"><x-ds.icon name="logout" :size="20" /> Log out</button></form>
        </div>
        <p class="sh-sheet__who">Signed in as {{ $user->name }}</p>
    </x-ds.dialog>

    <x-ds.toasts />
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
