<x-app-layout title="Dashboard" :title-visible="false">

<div class="dot-page">

    {{-- ────────────────────────── PAGE HEADER ────────────────────────── --}}
    <div class="dot-head">
        <div>
            <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--c-ink);letter-spacing:-0.02em;">
                Auction Dashboard
            </div>
            <div style="font-size:0.78rem;color:var(--c-ink-3);margin-top:0.25rem;">
                {{ now()->format('l, F j Y') }} &nbsp;·&nbsp; Your auction activity at a glance
            </div>
        </div>
        <div class="dot-head-actions">
            <a href="{{ route('auctions.index') }}" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:var(--c-sunken);border:1px solid var(--c-line);padding:0.7rem 1.4rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:var(--c-ink);text-decoration:none;transition:background 0.2s;">
                <x-ds.icon name="browse" :size="18" />
                Browse Auctions
            </a>
            <a href="{{ route('seller.auctions.create') }}" class="dot-btn dot-btn-primary" style="border-radius:9999px;padding:0.7rem 1.4rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;">
                <x-ds.icon name="plus" :size="18" />
                List an auction
            </a>
        </div>
    </div>

    {{-- ────────────────────────── KPI ROW ────────────────────────── --}}
    <div class="dot-kpi-grid">

        {{-- Active Auctions --}}
        <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;" class="dot-kpi">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:var(--c-notice-bg);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:var(--c-notice-bg);display:flex;align-items:center;justify-content:center;">
                    <x-ds.icon name="gavel" :size="20" />
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.1em;">Active Auctions</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:var(--c-notice);line-height:1;">
                {{ $activeAuctions }}
            </div>
            <div style="font-size:0.7rem;color:var(--c-notice);margin-top:0.4rem;font-weight:600;">
                <x-ds.icon name="play" :size="13" />
                Live now
            </div>
        </div>

        {{-- Total Auctions --}}
        <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;" class="dot-kpi">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:var(--c-scheduled-bg);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:var(--c-scheduled-bg);display:flex;align-items:center;justify-content:center;">
                    <x-ds.icon name="browse" :size="20" />
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.1em;">Total Auctions</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:var(--c-ink);line-height:1;">
                {{ $totalAuctions }}
            </div>
            <div style="font-size:0.7rem;color:var(--c-scheduled);margin-top:0.4rem;font-weight:600;">
                All time listings
            </div>
        </div>

        {{-- Total Bids --}}
        <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;" class="dot-kpi">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:var(--c-scheduled-bg);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:var(--c-scheduled-bg);display:flex;align-items:center;justify-content:center;">
                    <x-ds.icon name="bids" :size="20" />
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.1em;">Total Bids</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:var(--c-scheduled);line-height:1;">
                {{ $totalBids }}
            </div>
            <div style="font-size:0.7rem;color:var(--c-scheduled);margin-top:0.4rem;font-weight:600;">
                Across your listings
            </div>
        </div>

        {{-- Categories --}}
        <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;" class="dot-kpi">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:var(--c-live-bg);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:var(--c-live-bg);display:flex;align-items:center;justify-content:center;">
                    <x-ds.icon name="tag" :size="20" />
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.1em;">Categories</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:var(--c-live);line-height:1;">
                {{ $categories->count() }}
            </div>
            <div style="font-size:0.7rem;color:var(--c-live);margin-top:0.4rem;font-weight:600;">
                Auction categories
            </div>
        </div>

        {{-- Watchlist --}}
        <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;" class="dot-kpi">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:var(--c-scheduled-bg);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:var(--c-scheduled-bg);display:flex;align-items:center;justify-content:center;">
                    <x-ds.icon name="bookmark" :size="20" />
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.1em;">Watchlist</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:var(--c-scheduled);line-height:1;">
                {{ $watchlistCount }}
            </div>
            <div style="font-size:0.7rem;color:var(--c-scheduled);margin-top:0.4rem;font-weight:600;">
                Auctions you're watching
            </div>
        </div>

    </div>

    {{-- ────────────────────────── AWAITING YOUR DECISION ────────────────────────── --}}
    @if($pendingReserveProposals->count())
    <div class="dot-card" style="margin-bottom:2rem;overflow:hidden;">
        <div style="padding:1.4rem 1.6rem;border-bottom:1px solid var(--c-line);">
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:var(--c-ink);">
                <x-ds.icon name="gavel" :size="16" />
                Awaiting Your Decision
            </div>
        </div>
        <div style="display:flex;flex-direction:column;">
            @foreach($pendingReserveProposals as $proposal)
                <livewire:auctions.reserve-decision-panel :proposal="$proposal" :key="$proposal->id" />
            @endforeach
        </div>
    </div>
    @endif

    {{-- ────────────────────────── ENDING SOON ────────────────────────── --}}
    @if($endingSoon->count())
    <div class="dot-card" style="margin-bottom:2rem;overflow:hidden;">
        <div style="padding:1.4rem 1.6rem;border-bottom:1px solid var(--c-line);display:flex;align-items:center;justify-content:space-between;">
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:var(--c-ink);">
                <x-ds.icon name="clock" :size="16" />
                Ending Soon (next 24h)
            </div>
            <a href="{{ route('auctions.index', ['status' => 'active']) }}" style="font-size:0.72rem;font-weight:600;color:var(--c-notice);text-decoration:none;">Browse all</a>
        </div>
        <div style="display:flex;flex-direction:column;">
            @foreach($endingSoon as $lot)
            <a href="{{ route('auctions.show', $lot) }}" class="dot-lot-row" >
                <div>
                    <div style="font-size:0.82rem;font-weight:600;color:var(--c-ink);font-family:'Syne',sans-serif;">{{ $lot->title }}</div>
                    <div style="font-size:0.7rem;color:var(--c-ink-3);margin-top:0.15rem;">{{ $lot->category->name ?? 'Uncategorized' }} · ends {{ $lot->ends_at->diffForHumans() }}</div>
                </div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:0.85rem;font-weight:700;color:var(--c-notice);">{{ \App\Models\Auction::money($lot->current_price) }}</div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ────────────────────────── CATEGORIES ROW ────────────────────────── --}}
    @if($categories->count())
    <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;padding:1.4rem 1.6rem;margin-bottom:2rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:var(--c-ink-3);text-transform:uppercase;letter-spacing:0.12em;margin-bottom:1rem;">
            Auction Categories
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
            @foreach($categories as $cat)
            <div style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.35rem 0.85rem;border-radius:9999px;background:var(--c-notice-bg);border:1px solid var(--c-notice);">
                <span style="font-size:0.75rem;font-weight:600;color:var(--c-notice);font-family:'Syne',sans-serif;">{{ $cat->name }}</span>
                <span style="font-size:0.65rem;background:var(--c-notice-bg);color:var(--c-notice);border-radius:9999px;padding:0.05rem 0.45rem;font-weight:700;">{{ $cat->auctions_count }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ────────────────────────── MY AUCTIONS ────────────────────────── --}}
    <div class="dot-panel" style="margin-bottom:2rem;overflow:hidden;">
        <div class="dot-panel-head">
            <h2 class="dot-section-title">My Auctions</h2>
            <div class="dot-row">
                <a href="{{ route('seller.auctions.index') }}" class="dot-link" style="font-size:0.78rem;">Manage all</a>
                <a href="{{ route('seller.auctions.create') }}" class="dot-btn dot-btn-ghost dot-btn-small">List an auction</a>
            </div>
        </div>
        @include('seller.auctions._table', ['auctions' => $recentAuctions])
    </div>

    {{-- ────────────────────────── AGRITECH MODULES ────────────────────────── --}}
    <div style="background:var(--c-surface);border:1px solid var(--c-line);border-radius:1rem;overflow:hidden;">
        <div style="padding:1.4rem 1.6rem;border-bottom:1px solid var(--c-line);display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:var(--c-ink);">AgriTech Intelligence Suite</div>
                <div style="font-size:0.7rem;color:var(--c-ink-3);margin-top:0.15rem;">9 smart farming modules — full integration roadmap</div>
            </div>
            <span style="font-size:0.65rem;padding:0.3rem 0.75rem;border-radius:9999px;background:var(--c-notice-bg);border:1px solid var(--c-notice);color:var(--c-notice);font-weight:700;text-transform:uppercase;letter-spacing:0.1em;">Coming Soon</span>
        </div>
        <div class="dot-module-grid">

            @php
            $agriModules = [
                ['icon' => 'agriculture',        'title' => 'Farm Operations',        'desc' => 'Crop planning, field maps & yield tracking'],
                ['icon' => 'water_drop',          'title' => 'Smart Irrigation',       'desc' => 'IoT sensor-driven water management'],
                ['icon' => 'precision_manufacturing','title'=> 'Equipment Fleet',      'desc' => 'Machinery scheduling & maintenance logs'],
                ['icon' => 'biotech',             'title' => 'Crop Disease Detection', 'desc' => 'AI image analysis for early disease alerts'],
                ['icon' => 'view_in_ar',          'title' => 'Farm Digital Twin',      'desc' => '3D virtual model of your entire farm'],
                ['icon' => 'pets',                'title' => 'Livestock Intelligence', 'desc' => 'Health monitoring & herd analytics'],
                ['icon' => 'eco',                 'title' => 'Carbon Credits',         'desc' => 'Measure, verify & trade carbon offsets'],
                ['icon' => 'account_balance',     'title' => 'Financial Intelligence', 'desc' => 'AgriFinance, loans & cost forecasting'],
                ['icon' => 'space_dashboard',     'title' => 'Farm Command Center',    'desc' => 'Unified real-time operations dashboard'],
            ];
            @endphp

            @foreach($agriModules as $mod)
            <div style="background:var(--c-surface);padding:1.25rem 1.4rem;display:flex;align-items:flex-start;gap:0.85rem;">
                <div style="width:38px;height:38px;border-radius:0.65rem;background:var(--c-notice-bg);border:1px solid var(--c-notice);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <x-ds.icon name="browse" :size="20" />
                </div>
                <div style="min-width:0;">
                    <div style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:var(--c-ink);margin-bottom:0.2rem;">{{ $mod['title'] }}</div>
                    <div style="font-size:0.68rem;color:var(--c-ink-3);line-height:1.45;">{{ $mod['desc'] }}</div>
                    <span style="display:inline-block;margin-top:0.5rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--c-notice);background:var(--c-notice-bg);border:1px solid var(--c-notice);border-radius:9999px;padding:0.15rem 0.55rem;">Planned</span>
                </div>
            </div>
            @endforeach

        </div>
    </div>

</div>

</x-app-layout>
