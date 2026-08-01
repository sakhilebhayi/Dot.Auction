<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    {{-- ────────────────────────── PAGE HEADER ────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;letter-spacing:-0.02em;">
                Auction Dashboard
            </div>
            <div style="font-size:0.78rem;color:#71717a;margin-top:0.25rem;">
                {{ now()->format('l, F j Y') }} &nbsp;·&nbsp; Your auction activity at a glance
            </div>
        </div>
        <div style="display:flex;gap:0.75rem;">
            <a href="{{ route('auctions.index') }}" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.09);padding:0.7rem 1.4rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#f4f4f5;text-decoration:none;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.06)'">
                <span class="material-symbols-rounded" style="font-size:18px;">storefront</span>
                Browse Auctions
            </a>
            <a href="#" title="Seller listing management — coming soon" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:linear-gradient(135deg,#d97706,#92400e);padding:0.7rem 1.4rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;box-shadow:0 8px 24px rgba(217,119,6,0.35);transition:opacity 0.2s;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
                List Auction
            </a>
        </div>
    </div>

    {{-- ────────────────────────── KPI ROW ────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1.25rem;margin-bottom:2rem;">

        {{-- Active Auctions --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:rgba(217,119,6,0.08);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:rgba(217,119,6,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#d97706;">gavel</span>
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Active Auctions</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:#fcd34d;line-height:1;">
                {{ $activeAuctions }}
            </div>
            <div style="font-size:0.7rem;color:#d97706;margin-top:0.4rem;font-weight:600;">
                <span class="material-symbols-rounded" style="font-size:13px;vertical-align:-2px;">radio_button_checked</span>
                Live now
            </div>
        </div>

        {{-- Total Auctions --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:rgba(99,102,241,0.08);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:rgba(99,102,241,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#818cf8;">storefront</span>
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Total Auctions</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:#f4f4f5;line-height:1;">
                {{ $totalAuctions }}
            </div>
            <div style="font-size:0.7rem;color:#818cf8;margin-top:0.4rem;font-weight:600;">
                All time listings
            </div>
        </div>

        {{-- Total Bids --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:rgba(168,85,247,0.08);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:rgba(168,85,247,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#c084fc;">trending_up</span>
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Total Bids</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:#c084fc;line-height:1;">
                {{ $totalBids }}
            </div>
            <div style="font-size:0.7rem;color:#a855f7;margin-top:0.4rem;font-weight:600;">
                Across your listings
            </div>
        </div>

        {{-- Categories --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:rgba(34,197,94,0.08);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#4ade80;">category</span>
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Categories</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:#4ade80;line-height:1;">
                {{ $categories->count() }}
            </div>
            <div style="font-size:0.7rem;color:#22c55e;margin-top:0.4rem;font-weight:600;">
                Auction categories
            </div>
        </div>

        {{-- Watchlist --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:1.4rem 1.6rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 1rem 0 80px;background:rgba(56,189,248,0.08);"></div>
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <div style="width:36px;height:36px;border-radius:0.6rem;background:rgba(56,189,248,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#38bdf8;">bookmark</span>
                </div>
                <span style="font-size:0.72rem;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Watchlist</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2.1rem;font-weight:800;color:#38bdf8;line-height:1;">
                {{ $watchlistCount }}
            </div>
            <div style="font-size:0.7rem;color:#0ea5e9;margin-top:0.4rem;font-weight:600;">
                Auctions you're watching
            </div>
        </div>

    </div>

    {{-- ────────────────────────── ENDING SOON ────────────────────────── --}}
    @if($endingSoon->count())
    <div class="dot-card" style="margin-bottom:2rem;overflow:hidden;">
        <div style="padding:1.4rem 1.6rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;">
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;">
                <span class="material-symbols-rounded" style="font-size:16px;vertical-align:-3px;color:#f59e0b;">timer</span>
                Ending Soon (next 24h)
            </div>
            <a href="{{ route('auctions.index', ['status' => 'active']) }}" style="font-size:0.72rem;font-weight:600;color:#d97706;text-decoration:none;">Browse all</a>
        </div>
        <div style="display:flex;flex-direction:column;">
            @foreach($endingSoon as $lot)
            <a href="{{ route('auctions.show', $lot) }}" style="display:flex;align-items:center;justify-content:space-between;padding:0.9rem 1.6rem;text-decoration:none;border-bottom:1px solid rgba(67,70,86,0.1);transition:background 0.15s;" onmouseover="this.style.background='rgba(217,119,6,0.04)'" onmouseout="this.style.background='transparent'">
                <div>
                    <div style="font-size:0.82rem;font-weight:600;color:#f4f4f5;font-family:'Syne',sans-serif;">{{ $lot->title }}</div>
                    <div style="font-size:0.7rem;color:#71717a;margin-top:0.15rem;">{{ $lot->category->name ?? 'Uncategorized' }} · ends {{ $lot->ends_at->diffForHumans() }}</div>
                </div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:0.85rem;font-weight:700;color:#fcd34d;">R{{ number_format($lot->current_price, 2) }}</div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ────────────────────────── CATEGORIES ROW ────────────────────────── --}}
    @if($categories->count())
    <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:1.4rem 1.6rem;margin-bottom:2rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:1rem;">
            Auction Categories
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
            @foreach($categories as $cat)
            <div style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(217,119,6,0.1);border:1px solid rgba(217,119,6,0.25);">
                <span style="font-size:0.75rem;font-weight:600;color:#fcd34d;font-family:'Syne',sans-serif;">{{ $cat->name }}</span>
                <span style="font-size:0.65rem;background:rgba(217,119,6,0.3);color:#d97706;border-radius:9999px;padding:0.05rem 0.45rem;font-weight:700;">{{ $cat->auctions_count }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ────────────────────────── RECENT AUCTIONS TABLE ────────────────────────── --}}
    <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;margin-bottom:2rem;overflow:hidden;">
        <div style="padding:1.4rem 1.6rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;">
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;">Recent Auctions</div>
            <a href="{{ route('auctions.index') }}" style="font-size:0.72rem;font-weight:600;color:#d97706;text-decoration:none;">View all</a>
        </div>

        @if($recentAuctions->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                        <th style="padding:0.85rem 1.6rem;text-align:left;font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Title</th>
                        <th style="padding:0.85rem 1rem;text-align:left;font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Category</th>
                        <th style="padding:0.85rem 1rem;text-align:left;font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Status</th>
                        <th style="padding:0.85rem 1rem;text-align:right;font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Starting</th>
                        <th style="padding:0.85rem 1rem;text-align:right;font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Current</th>
                        <th style="padding:0.85rem 1.6rem 0.85rem 1rem;text-align:left;font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Ends At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentAuctions as $auction)
                    <tr style="border-bottom:1px solid rgba(67,70,86,0.1);transition:background 0.15s;" onmouseover="this.style.background='rgba(217,119,6,0.04)'" onmouseout="this.style.background='transparent'">

                        {{-- Title --}}
                        <td style="padding:1rem 1.6rem;">
                            <div style="font-size:0.82rem;font-weight:600;color:#f4f4f5;font-family:'Syne',sans-serif;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                {{ $auction->title }}
                            </div>
                            @if($auction->buy_now_price)
                            <div style="font-size:0.65rem;color:#d97706;margin-top:0.2rem;font-weight:600;">
                                Buy Now: ${{ number_format($auction->buy_now_price, 2) }}
                            </div>
                            @endif
                        </td>

                        {{-- Category --}}
                        <td style="padding:1rem;">
                            @if($auction->category)
                            <span style="font-size:0.7rem;padding:0.25rem 0.6rem;border-radius:9999px;background:rgba(99,102,241,0.12);border:1px solid rgba(99,102,241,0.2);color:#a5b4fc;font-weight:600;">
                                {{ $auction->category->name }}
                            </span>
                            @else
                            <span style="font-size:0.7rem;color:#555e7a;">—</span>
                            @endif
                        </td>

                        {{-- Status --}}
                        <td style="padding:1rem;">
                            @if($auction->status === 'active')
                            <span style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.7rem;padding:0.25rem 0.65rem;border-radius:9999px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-weight:600;">
                                <span style="width:5px;height:5px;border-radius:9999px;background:#22c55e;animation:pulse 2s infinite;flex-shrink:0;"></span>
                                Active
                            </span>
                            @elseif($auction->status === 'draft')
                            <span style="font-size:0.7rem;padding:0.25rem 0.65rem;border-radius:9999px;background:rgba(107,114,128,0.15);border:1px solid rgba(107,114,128,0.25);color:#9ca3af;font-weight:600;">
                                Draft
                            </span>
                            @elseif($auction->status === 'ended')
                            <span style="font-size:0.7rem;padding:0.25rem 0.65rem;border-radius:9999px;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.25);color:#93c5fd;font-weight:600;">
                                Ended
                            </span>
                            @elseif($auction->status === 'cancelled')
                            <span style="font-size:0.7rem;padding:0.25rem 0.65rem;border-radius:9999px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;font-weight:600;">
                                Cancelled
                            </span>
                            @endif
                        </td>

                        {{-- Starting Price --}}
                        <td style="padding:1rem;text-align:right;">
                            <span style="font-size:0.8rem;font-weight:600;color:#a1a1aa;font-family:'Syne',sans-serif;">
                                ${{ number_format($auction->starting_price, 2) }}
                            </span>
                        </td>

                        {{-- Current Price --}}
                        <td style="padding:1rem;text-align:right;">
                            <span style="font-size:0.8rem;font-weight:700;color:#fcd34d;font-family:'Syne',sans-serif;">
                                ${{ number_format($auction->current_price, 2) }}
                            </span>
                        </td>

                        {{-- Ends At --}}
                        <td style="padding:1rem 1.6rem 1rem 1rem;">
                            <span style="font-size:0.75rem;color:#71717a;">
                                {{ $auction->ends_at->format('M j, Y') }}
                            </span>
                            <div style="font-size:0.65rem;color:#555e7a;margin-top:0.1rem;">
                                {{ $auction->ends_at->format('g:i A') }}
                            </div>
                        </td>

                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:3rem 1.6rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:40px;color:#374151;display:block;margin-bottom:0.75rem;">gavel</span>
            <div style="font-size:0.85rem;color:#555e7a;font-weight:500;">No auctions yet. List your first auction to get started.</div>
            <a href="#" style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:1rem;font-size:0.78rem;font-weight:600;color:#d97706;text-decoration:none;">
                <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                List Auction
            </a>
        </div>
        @endif
    </div>

    {{-- ────────────────────────── AGRITECH MODULES ────────────────────────── --}}
    <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:1rem;overflow:hidden;">
        <div style="padding:1.4rem 1.6rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;">AgriTech Intelligence Suite</div>
                <div style="font-size:0.7rem;color:#71717a;margin-top:0.15rem;">9 smart farming modules — full integration roadmap</div>
            </div>
            <span style="font-size:0.65rem;padding:0.3rem 0.75rem;border-radius:9999px;background:rgba(217,119,6,0.1);border:1px solid rgba(217,119,6,0.3);color:#d97706;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;">Coming Soon</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:rgba(67,70,86,0.15);">

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
            <div style="background:#141416;padding:1.25rem 1.4rem;display:flex;align-items:flex-start;gap:0.85rem;">
                <div style="width:38px;height:38px;border-radius:0.65rem;background:rgba(217,119,6,0.1);border:1px solid rgba(217,119,6,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#d97706;">{{ $mod['icon'] }}</span>
                </div>
                <div style="min-width:0;">
                    <div style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#f4f4f5;margin-bottom:0.2rem;">{{ $mod['title'] }}</div>
                    <div style="font-size:0.68rem;color:#71717a;line-height:1.45;">{{ $mod['desc'] }}</div>
                    <span style="display:inline-block;margin-top:0.5rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#92400e;background:rgba(146,64,14,0.15);border:1px solid rgba(146,64,14,0.3);border-radius:9999px;padding:0.15rem 0.55rem;">Planned</span>
                </div>
            </div>
            @endforeach

        </div>
    </div>

</div>

</x-app-layout>
