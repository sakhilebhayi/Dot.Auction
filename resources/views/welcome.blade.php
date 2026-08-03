<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Auction — Live Bidding, Real Time</title>
        <meta name="description" content="List items, place live bids, and win with confidence. Dot.Auction is the live-bidding platform of the Dot Ecosystem.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-16px); }
            }
            .float-animation { animation: float 6s ease-in-out infinite; }
        </style>
    </head>
    <body class="bg-gray-900 text-gray-100 antialiased">

        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false, mobileMenuOpen: false }"
                @scroll.window="scrolled = window.pageYOffset > 50"
                :class="scrolled ? 'bg-gray-900/95 backdrop-blur-xl shadow-lg border-b border-gray-800' : 'bg-transparent'">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <a href="/" class="flex items-center gap-3 group">
                        <img src="{{ asset('images/logo.png') }}" alt="Dot.Auction" class="h-14 w-auto transform group-hover:scale-105 transition-transform duration-300">
                        <p class="hidden sm:block text-xs text-amber-400 font-medium border-l border-gray-700 pl-3">Live Bidding Platform</p>
                    </a>

                    <div class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-300 hover:text-amber-400 transition-colors font-medium">Features</a>
                        <a href="#how-it-works" class="text-gray-300 hover:text-amber-400 transition-colors font-medium">How It Works</a>
                        <a href="#platform" class="text-gray-300 hover:text-amber-400 transition-colors font-medium">Platform</a>
                    </div>

                    @if (Route::has('login'))
                        <div class="flex items-center gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="hidden sm:flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-amber-500/30 transform hover:scale-105">
                                    <span>Dashboard</span>
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="hidden sm:block px-4 py-2 text-gray-300 hover:text-white transition-colors font-medium">Sign In</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-amber-500/30 transform hover:scale-105">
                                        <span>Get Started</span>
                                    </a>
                                @endif
                            @endauth
                            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-gray-400 hover:text-white">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                    <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     class="md:hidden mt-4 py-4 border-t border-gray-800"
                     style="display: none;">
                    <div class="flex flex-col gap-2">
                        <a href="#features" class="px-4 py-2 text-gray-300 hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-colors">Features</a>
                        <a href="#how-it-works" class="px-4 py-2 text-gray-300 hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-colors">How It Works</a>
                        @guest
                            <a href="{{ route('login') }}" class="px-4 py-2 text-gray-300 hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-colors">Sign In</a>
                        @endguest
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
            <!-- Photographic Background: real auction-gavel-on-dark-surface photo by Sasun Bughdaryan (@sasun1990), unsplash.com/photos/wooden-gavel-resting-on-a-dark-surface-next-to-book-FaTLrG5-ViE -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1767972463877-b64ba4283cd0?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/85 to-gray-900/60"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/40 to-transparent"></div>

            <div class="absolute top-20 left-10 w-64 h-64 bg-amber-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation"></div>
            <div class="absolute bottom-20 right-10 w-96 h-96 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation" style="animation-delay: 2s;"></div>

            <div class="relative z-10 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500/10 border border-amber-500/20 rounded-full text-amber-400 text-sm font-medium mb-8">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    <span>Real-Time Bidding, Powered by Laravel Reverb</span>
                </div>

                <h1 class="text-5xl lg:text-7xl font-bold leading-tight mb-6">
                    <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent">List Items, Place</span><br>
                    <span class="bg-gradient-to-r from-amber-400 via-amber-500 to-amber-600 bg-clip-text text-transparent">Live Bids</span>
                    <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent"> & Win</span>
                </h1>

                <p class="text-xl text-gray-400 leading-relaxed max-w-2xl mx-auto mb-10">
                    Dot.Auction is the live-bidding platform of the Dot Ecosystem. Sellers list items with a starting price and a fixed window, buyers bid in real time, and every watcher sees the price move the instant a bid lands — no polling, no refresh.
                </p>

                @guest
                    <div class="flex flex-wrap items-center justify-center gap-4">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-amber-500/30 transform hover:scale-105">
                            <span>Start Selling & Bidding</span>
                        </a>
                        <a href="#how-it-works" class="flex items-center gap-2 px-8 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>See How It Works</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-900/50 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-amber-500/5 to-transparent"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500/10 border border-amber-500/20 rounded-full text-amber-400 text-sm font-medium mb-6">
                        <span>Core Features</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Built for the Moment<br>
                        <span class="bg-gradient-to-r from-amber-400 to-amber-600 bg-clip-text text-transparent">the Gavel Falls</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">What's actually running in this platform today</p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach([
                        ['title' => 'Live Bidding', 'desc' => "Every bid broadcasts over a dedicated Reverb WebSocket channel per auction, so the current price, bidder, and history update for every connected watcher the instant a bid is placed.", 'color' => 'from-blue-500 to-blue-600'],
                        ['title' => 'Reserve & Buy-Now Pricing', 'desc' => 'Sellers set a starting price, an optional reserve, a bid increment, and an optional buy-now price — the platform enforces the minimum legal next bid automatically.', 'color' => 'from-amber-500 to-amber-600'],
                        ['title' => 'Seller Dashboard', 'desc' => 'An authenticated dashboard for sellers to create and manage listings, track the current leader, and follow an auction through its draft → active → ended lifecycle.', 'color' => 'from-purple-500 to-purple-600'],
                        ['title' => 'Categories & Discovery', 'desc' => 'Auctions can be organised under categories, with a searchable, filterable marketplace at /auctions for buyers to browse by title, category, and status.', 'color' => 'from-green-500 to-green-600'],
                        ['title' => 'Watchlists', 'desc' => 'Buyers can watch auctions they care about and get notified when they get outbid — a real, live-triggered notification, not a scheduled digest.', 'color' => 'from-red-500 to-red-600'],
                        ['title' => 'Multi-Item Lots', 'desc' => 'An auction can bundle multiple physical items under one listing, each with its own condition and location detail, for sellers moving more than a single lot at a time.', 'color' => 'from-indigo-500 to-indigo-600'],
                    ] as $f)
                        <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2">
                            <div class="w-14 h-14 bg-gradient-to-br {{ $f['color'] }} rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">{{ $f['title'] }}</h3>
                            <p class="text-gray-400 leading-relaxed">{{ $f['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- How It Works -->
        <section id="how-it-works" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900"></div>

            <div class="relative z-10 max-w-5xl mx-auto">
                <div class="text-center mb-16">
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        From Listing to <span class="bg-gradient-to-r from-amber-400 to-amber-600 bg-clip-text text-transparent">Settlement</span>
                    </h2>
                </div>

                <div class="grid md:grid-cols-4 gap-6">
                    @foreach([
                        ['step' => '1', 'title' => 'List', 'desc' => 'Seller sets starting price, reserve, bid increment, and an auction window.'],
                        ['step' => '2', 'title' => 'Bid', 'desc' => 'Buyers place live bids; the current price and leader update in real time for everyone watching.'],
                        ['step' => '3', 'title' => 'Watch', 'desc' => 'Buyers add auctions to a watchlist and get notified the moment they are outbid.'],
                        ['step' => '4', 'title' => 'Win', 'desc' => 'When the clock runs out, the highest bid over reserve wins the lot.'],
                    ] as $s)
                        <div class="bg-gray-800/60 border border-gray-700 rounded-2xl p-6 text-center">
                            <div class="w-10 h-10 mx-auto mb-4 rounded-full bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-amber-400 font-bold">{{ $s['step'] }}</div>
                            <h3 class="text-lg font-bold text-white mb-2">{{ $s['title'] }}</h3>
                            <p class="text-sm text-gray-400 leading-relaxed">{{ $s['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Platform / Ecosystem -->
        <section id="platform" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-900/50">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-14">
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">Part of the <span class="bg-gradient-to-r from-amber-400 to-amber-600 bg-clip-text text-transparent">Dot Ecosystem</span></h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">Dot.Auction runs on the ecosystem's shared PostgreSQL instance and authenticates through the same ecosystem SSO handoff as every other Dot platform — a deliberate integration choice, not scaffolding left behind.</p>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach([
                        ['label' => 'Laravel 12 / PHP 8.4', 'desc' => 'Core framework'],
                        ['label' => 'Livewire 3 + Alpine.js', 'desc' => 'Interactive UI'],
                        ['label' => 'Laravel Reverb', 'desc' => 'Live bid broadcasting'],
                        ['label' => 'Ecosystem SSO', 'desc' => 'Jetstream + Sanctum'],
                    ] as $item)
                        <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 text-center">
                            <p class="font-bold text-sm text-white mb-1">{{ $item['label'] }}</p>
                            <p class="text-xs text-gray-400">{{ $item['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-amber-950/30 to-gray-900"></div>
            <div class="relative z-10 max-w-4xl mx-auto text-center">
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                    Ready to List, Bid, or <span class="bg-gradient-to-r from-amber-400 to-amber-600 bg-clip-text text-transparent">Watch a Lot?</span>
                </h2>
                <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto">Join the Dot Ecosystem's live-bidding platform.</p>
                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-10 py-4 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-amber-500/30 transform hover:scale-105">
                            <span>Get Started</span>
                        </a>
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-10 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>Sign In</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-gray-800 bg-gray-900/50">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Auction" class="h-12 w-auto">
                    <p class="text-gray-400 text-sm">&copy; {{ date('Y') }} Dot.Auction. Part of the Dot Ecosystem.</p>
                </div>
            </div>
        </footer>

    </body>
</html>
