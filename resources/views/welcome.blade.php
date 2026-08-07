<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Auction — Live bidding, tracked to the close</title>
        <meta name="description" content="Sellers list a lot with a starting price and a fixed window. Buyers bid in real time. Dot.Auction tracks the current highest bid, enforces the reserve, and broadcasts every move over WebSockets — no polling, no refreshing.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,900&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        {{-- resources/js/app.js does not bundle Alpine (no alpinejs in package.json), and this
             guest page renders outside layouts/app.blade.php, so the x-data/@click/x-show
             directives below need Alpine loaded directly — same CDN pin already used in
             layouts/app.blade.php for the authenticated layout. --}}
        <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>

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
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--ink); }
            body { font-family: var(--font-body); background: var(--ink); color: var(--stone); }
            .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(244, 236, 224, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#17110f]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    <img src="{{ asset('images/logo-light.png') }}" alt="Dot.Auction" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--stone)]">
                    <a href="#lots" class="link-underline hover:text-[var(--paper)] pb-0.5">Lots</a>
                    <a href="#how-it-works" class="link-underline hover:text-[var(--paper)] pb-0.5">How it works</a>
                    <a href="#platform" class="link-underline hover:text-[var(--paper)] pb-0.5">Platform</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#17110f] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--stone)] hover:text-[var(--paper)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#17110f] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Get started
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--paper)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[#17110f]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#lots" class="px-3 py-2.5 text-[var(--stone)] hover:text-[var(--paper)]">Lots</a>
                    <a href="#how-it-works" class="px-3 py-2.5 text-[var(--stone)] hover:text-[var(--paper)]">How it works</a>
                    <a href="#platform" class="px-3 py-2.5 text-[var(--stone)] hover:text-[var(--paper)]">Platform</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--stone)] hover:text-[var(--paper)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden">
            <!-- Photo: wooden gavel resting on a dark surface, by Sasun Bughdaryan (@sasun1990), unsplash.com/photos/wooden-gavel-resting-on-a-dark-surface-next-to-book-FaTLrG5-ViE -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1767972463877-b64ba4283cd0?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(23,17,15,0.55) 0%, rgba(23,17,15,0.74) 45%, #17110f 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, #17110f 0%, rgba(23,17,15,0.55) 38%, transparent 68%);"></div>

            <!-- Gavel silhouette — line-art nod to the real gavel icon in the Dot.Auction mark -->
            <svg class="hidden lg:block absolute right-[6%] bottom-0 h-[70%] w-auto opacity-[0.14] pointer-events-none" viewBox="0 0 240 260" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <g transform="rotate(-32 120 100)">
                    <rect x="70" y="40" width="100" height="46" rx="14" stroke="#f4ece0" stroke-width="3"/>
                    <line x1="70" y1="63" x2="170" y2="63" stroke="#f4ece0" stroke-width="1.5"/>
                    <rect x="110" y="82" width="20" height="130" rx="10" stroke="#f4ece0" stroke-width="3"/>
                </g>
                <rect x="40" y="214" width="160" height="20" rx="5" stroke="#f4ece0" stroke-width="3"/>
                <rect x="64" y="234" width="112" height="12" rx="4" stroke="#f4ece0" stroke-width="3"/>
                <path d="M44 30L58 44M76 16L84 34M104 10L106 30" stroke="#f4ece0" stroke-width="2" stroke-linecap="round"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                        Live-bidding platform
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.08] tracking-tight text-[var(--paper)] mb-6">
                        The price moves the instant a bid lands.
                    </h1>

                    <p class="text-lg text-[var(--stone)] leading-relaxed max-w-xl mb-10">
                        A seller lists a lot with a starting price and a fixed window. Buyers bid in real time. Dot.Auction tracks the current highest bid, enforces the reserve, and carries the lot to its close — no refreshing, no polling, no missed bids.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#17110f] font-display font-semibold rounded-lg transition-colors">
                                List an item
                            </a>
                            <a href="#how-it-works" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--stone)] transition-colors">
                                See how bidding works
                            </a>
                        </div>
                    @endguest
                </div>
            </div>

            <!-- Live capability strip — what's actually shipped, not a fabricated metric -->
            <div class="relative z-10 w-full border-t border-[var(--line)] bg-[#17110f]/60 backdrop-blur-sm">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-6 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--stone)]">
                    <span class="inline-flex items-center gap-6">Live Reverb broadcast <span class="text-[var(--red)]">&rsaquo;</span></span>
                    <span class="inline-flex items-center gap-6">Reserve &amp; buy-now pricing <span class="text-[var(--red)]">&rsaquo;</span></span>
                    <span class="inline-flex items-center gap-6">Watchlist &amp; outbid alerts <span class="text-[var(--red)]">&rsaquo;</span></span>
                    <span>Seller dashboard</span>
                </div>
            </div>
        </section>

        <!-- Lots (features) -->
        <section id="lots" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">Shipped, not roadmap</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight">
                        What's actually running today
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $lots = [
                            ['lot' => 'Lot 01', 'title' => 'Live bidding', 'body' => 'Every bid broadcasts on a dedicated Reverb WebSocket channel per auction, carrying the new amount, the bidder, and the updated price — so every connected watcher sees it move without refreshing.'],
                            ['lot' => 'Lot 02', 'title' => 'Reserve & buy-now pricing', 'body' => 'A seller sets a starting price, an optional reserve, a bid increment, and an optional buy-now price. The next legal bid is derived from the current price automatically.'],
                            ['lot' => 'Lot 03', 'title' => 'Seller dashboard', 'body' => 'An authenticated dashboard to create and manage listings, watch the current leader on each lot, and follow it through draft, active, and ended.'],
                            ['lot' => 'Lot 04', 'title' => 'Categories & discovery', 'body' => 'Auctions sit under a simple category taxonomy, with a searchable, filterable marketplace for signed-in buyers to browse by title, category, and status.'],
                            ['lot' => 'Lot 05', 'title' => 'Watchlists & outbid alerts', 'body' => 'Buyers watch the lots they care about and get a real, live-triggered notification the moment they\'re outbid — not a scheduled digest.'],
                            ['lot' => 'Lot 06', 'title' => 'Multi-item lots', 'body' => 'One auction can bundle several physical items under a single listing, each with its own condition and location on file.'],
                        ];
                    @endphp
                    @foreach ($lots as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--red)] mb-3">{{ $f['lot'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--paper)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--stone)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section id="how-it-works" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink-soft)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">The lifecycle of a lot</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight">
                        From listing to the close
                    </h2>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-px bg-[var(--line)] border border-[var(--line)]">
                    @php
                        $steps = [
                            ['n' => '01', 'title' => 'List', 'body' => 'A seller sets the starting price, reserve, bid increment, and the window the auction runs.'],
                            ['n' => '02', 'title' => 'Bid', 'body' => 'Buyers place live bids; the price and the leader update for everyone watching, instantly.'],
                            ['n' => '03', 'title' => 'Watch', 'body' => 'Buyers add lots to a watchlist and hear the moment they\'re outbid.'],
                            ['n' => '04', 'title' => 'Close', 'body' => 'The window ends. The leading bid over reserve takes the lot.'],
                        ];
                    @endphp
                    @foreach ($steps as $s)
                        <div class="bg-[var(--ink-soft)] p-7 reveal" data-reveal>
                            <p class="font-mono text-xs text-[var(--red)] mb-4">{{ $s['n'] }}</p>
                            <h3 class="font-display font-semibold text-lg text-[var(--paper)] mb-2">{{ $s['title'] }}</h3>
                            <p class="text-sm text-[var(--stone)] leading-relaxed">{{ $s['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Platform / Ecosystem -->
        <section id="platform" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">Built on the shared stack</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                            One shared foundation, not its own island
                        </h2>
                        <p class="text-[var(--stone)] leading-relaxed max-w-sm">
                            Dot.Auction runs on the Dot Ecosystem's shared Postgres instance and shared sign-in. Real-time bidding is the one thing it has to get exactly right.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $platform = [
                                ['title' => 'Shared database', 'body' => 'Runs on the ecosystem\'s shared PostgreSQL 16 instance, not an isolated database of its own.'],
                                ['title' => 'Ecosystem SSO', 'body' => 'Signs in through the shared ecosystem auth handoff and Sanctum tokens — the same pattern as every other Dot platform.'],
                                ['title' => 'Live bid broadcasting', 'body' => 'A placed bid fires an event on a per-auction Reverb channel the instant it lands — the architectural centerpiece of the platform.'],
                                ['title' => 'Reserve confidentiality', 'body' => 'Buyer-facing views only ever learn whether the reserve is met, never the raw reserve price itself.'],
                            ];
                        @endphp
                        @foreach ($platform as $c)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--paper)] mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[var(--stone)] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden bg-[var(--ink-soft)] border-t border-[var(--line)]">
            <svg class="hidden md:block absolute left-[-4%] top-1/2 -translate-y-1/2 h-[130%] w-auto opacity-[0.06] pointer-events-none" viewBox="0 0 240 260" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <g transform="rotate(-32 120 100)">
                    <rect x="70" y="40" width="100" height="46" rx="14" stroke="#f4ece0" stroke-width="3"/>
                    <line x1="70" y1="63" x2="170" y2="63" stroke="#f4ece0" stroke-width="1.5"/>
                    <rect x="110" y="82" width="20" height="130" rx="10" stroke="#f4ece0" stroke-width="3"/>
                </g>
                <rect x="40" y="214" width="160" height="20" rx="5" stroke="#f4ece0" stroke-width="3"/>
                <rect x="64" y="234" width="112" height="12" rx="4" stroke="#f4ece0" stroke-width="3"/>
            </svg>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Ready to list a lot, or start watching one?
                </h2>
                <p class="text-[var(--stone)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Create an account to list an item as a seller, or sign in to browse what's live and add a lot to your watchlist.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#17110f] font-display font-semibold rounded-lg transition-colors">
                            Get started
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--stone)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('images/logo-light.png') }}" alt="Dot.Auction" class="h-11 w-auto opacity-90">
                </a>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--stone)]">
                    <a href="{{ route('policy.show') }}" class="hover:text-[var(--paper)] transition-colors">Privacy</a>
                    <a href="{{ route('cookies') }}" class="hover:text-[var(--paper)] transition-colors">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="hover:text-[var(--paper)] transition-colors">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--stone)]">
                    &copy; {{ date('Y') }} Dot.Auction. Live-bidding platform of the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
