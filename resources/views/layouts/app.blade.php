{{--
  The signed-in shell: a rail on wide screens, a top bar and a bottom bar on phones. See DESIGN.md "Shell".
  Rendered by App\View\Components\AppLayout (props: title, description, width, back, backLabel, titleVisible; slots: header, meta, actions, topbar).
  Landmarks: skip link, banner (top bar), navigation (rail or phone bar), main (one h1). Slots a UI agent may fill:
    $hero     a page's own heading block, in place of the page header (it must hold the h1)
    $header   the page's h1 text      $meta     chips above the h1      $actions   buttons beside the h1      $topbar   extra controls in the top bar
  The notification bell is the Livewire component <livewire:notification-bell /> (its content belongs to the discover workstream).
--}}
@php
    use App\Services\Paystack\PaystackConfig;
    $payMode = rescue(fn () => app(PaystackConfig::class)->mode(), null, false);
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="dot-theme">
<head>
    @include('layouts.partials.head', ['title' => $title, 'description' => $description, 'robots' => 'noindex', 'dotUiBundle' => true])
    {{-- Shared Dot UI 0.6.1 (header, search pill, platform switcher: dot-ui.min.css is linked first in the head partial, dot-ui-core.min.js here), served from this site's own public/dot-ui.
         The host mapping block is in resources/css/shell.css. --}}
    <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" nonce="{{ csp_nonce() }}" defer></script>
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="sh-body">
    <x-banner />

    @php
        $team = $user->currentTeam;
        $hasTeams = Laravel\Jetstream\Jetstream::hasTeamFeatures() && $team;
        // On Browse the header search keeps the tab, category and sort the visitor has chosen.
        $keepFields = request()->routeIs('auctions.index')
            ? collect(['state', 'category', 'sort'])->mapWithKeys(fn ($k) => [$k => request()->query($k)])->filter(fn ($v) => is_string($v) && $v !== '')->all()
            : [];
        $accountLinks = [['label' => 'Profile and security', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')]];
        if ($hasTeams) {
            $accountLinks[] = ['rule' => true];
            $accountLinks[] = ['label' => 'Team settings', 'href' => route('teams.show', $team->id)];
            if (Gate::forUser($user)->check('create', Laravel\Jetstream\Jetstream::newTeamModel())) {
                $accountLinks[] = ['label' => 'Create team', 'href' => route('teams.create')];
            }
            if ($user->allTeams()->count() > 1) {
                $accountLinks[] = ['heading' => 'Switch team'];
                foreach ($user->allTeams() as $switchTeam) {
                    $accountLinks[] = ['label' => $switchTeam->name, 'current' => $user->isCurrentTeam($switchTeam), 'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $switchTeam->id]]];
                }
            }
        }
        $account = [
            'name' => $user->name, 'email' => $user->email, 'team' => $team?->name,
            'links' => $accountLinks, 'signOut' => ['action' => route('logout'), 'label' => 'Log out'], 'theme' => true,
        ];
    @endphp

    {{-- One header for every width: the logo, the search (centred on the header grid, a full-screen sheet on phones), the platform switcher, the bell and the account.
         The rail below it is this platform's own navigation on a wide screen; on a phone the same destinations are in the shared drawer (the menu button, or "More" in the bottom bar). --}}
    <x-dot.header brand-href="{{ route('dashboard') }}" brand-label="Dot.Auction home" skip="#main" drawer-title="Menu" :account="$account" class="dh--swpanel">
        <x-slot:brand>
            <x-ds.brand variant="lockup" :width="74" class="sh-brand sh-brand--lockup" />
            <x-ds.brand variant="mark" :width="52" class="sh-brand sh-brand--mark" />
        </x-slot:brand>
        <x-slot:search>
            <x-dot.search id="shell-search" name="q" :value="request()->routeIs('auctions.index') && is_string(request()->query('q')) ? request()->query('q') : ''" placeholder="Search lots" label="Search lots" :action="route('auctions.index')" :form-attributes="['id' => 'shell-search-form']" :hidden-fields="$keepFields" />
        </x-slot:search>
        <x-slot:tools>
            @isset($topbar){{ $topbar }}@endisset
            @if ($payMode === 'test')
                <x-ds.badge tone="notice" class="sh-mode" title="Paystack test keys are in use: no real money moves">Test mode</x-ds.badge>
            @elseif ($payMode === 'live')
                <x-ds.badge tone="urgent" class="sh-mode" title="Real money moves">Live mode</x-ds.badge>
            @endif
            <x-dot.switcher platform="auction" :company="$team?->infodot_team_uuid" :company-name="$team?->name" logos="/dot-ui/img/platforms" />
            <livewire:notification-bell />
        </x-slot:tools>
        <x-slot:drawer-before>
            @if ($payMode === 'test')
                <p class="sh-drawer-mode"><x-ds.badge tone="notice">Test mode</x-ds.badge> No real money moves.</p>
            @elseif ($payMode === 'live')
                <p class="sh-drawer-mode"><x-ds.badge tone="urgent">Live mode</x-ds.badge> Real money moves.</p>
            @endif
        </x-slot:drawer-before>
        <x-slot:drawer>
            @include('layouts.partials.nav-items', ['variant' => 'drawer'])
        </x-slot:drawer>
    </x-dot.header>

    <div class="sh">
        <nav class="sh-side" aria-label="Main">
            <div class="sh-side__list">
                @include('layouts.partials.nav-items', ['variant' => 'rail'])
            </div>
            <p class="sh-side__foot">Times shown in {{ config('auction.display_timezone', 'Africa/Johannesburg') === 'Africa/Johannesburg' ? 'South African time' : config('auction.display_timezone') }}</p>
        </nav>

        <div class="sh-col">
            <main id="main" class="sh-main" tabindex="-1">
                <div class="sh-page sh-page--{{ $width }}">
                    @php
                        // A header slot arrives already escaped (its {{ }} ran once), and x-ds.page-header escapes its title prop again. Drop any
                        // markup, decode the slot's entities once, and let the component do the one escape: "ship's" must not read "ship&#039;s".
                        $headTitle = isset($header) && trim((string) $header) !== ''
                            ? html_entity_decode(strip_tags((string) $header), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                            : $title;
                    @endphp
                    @isset($hero)
                        {{-- A page that draws its own heading block (Home's landing) owns the one h1, id="page-title" included. --}}
                        {{ $hero }}
                    @elseif ($headTitle)
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
            <button type="button" class="ds-nav ds-nav--bar" data-dh-menu-button aria-controls="dh-drawer" aria-expanded="false"><x-ds.icon name="menu" :size="22" /><span class="ds-nav__label">More</span></button>
        </nav>
    </div>

    <x-ds.toasts />
    @stack('modals')
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
