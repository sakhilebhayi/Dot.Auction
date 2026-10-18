<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <title>Dot.Auction</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } }</script>
    <script>
        // Dark mode: this dashboard's own markup is intentionally fixed-dark (inline styles,
        // not Tailwind dark: classes), so this toggle mainly affects Jetstream-rendered pages
        // (Profile, Team Settings) which do use dark: classes. Kept for theme consistency
        // ecosystem-wide and so those subpages respect the user's light/dark preference.
        (function () {
            const stored = localStorage.getItem('dot-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        :root { --accent: #ef4444; --accent-rgb: 239,68,68; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:#09090b; color:#f4f4f5; font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }

        /* Sidebar */
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; background:#0d0d10; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(239,68,68,0.1) 0%, transparent 65%); pointer-events:none; }

        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        .brand-icon { width:36px; height:36px; border-radius:10px; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.22); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .brand-icon .material-symbols-rounded { font-size:18px; color:#ef4444; }
        .brand-name { font-family:'Syne',sans-serif; font-size:14.5px; font-weight:700; color:#f4f4f5; letter-spacing:-0.01em; line-height:1.2; }
        .brand-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#ef4444; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(239,68,68,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(239,68,68,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:#3f3f46; text-transform:uppercase; letter-spacing:0.09em; }

        .sidebar-divider { height:1px; background:rgba(255,255,255,0.06); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:#3f3f46; text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:#71717a; text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; transform:translateX(1px); }
        .nav-item.active { background:rgba(239,68,68,0.1); color:#ef4444; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }

        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(239,68,68,0.18); border:1px solid rgba(239,68,68,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#ef4444; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:#d4d4d8; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:#52525b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Topbar */
        .topbar { position:fixed; top:0; left:260px; right:0; height:54px; background:rgba(9,9,11,0.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#f4f4f5; flex:1; }
        .topbar-team { font-size:11px; color:#52525b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color:#71717a; cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(255,255,255,0.09); color:#d4d4d8; }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* Content */
        .content-wrap { margin-left:260px; padding-top:54px; min-height:100vh; }

        /* Shared UI tokens */
        .dot-card { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
        .dot-card:hover { border-color:rgba(255,255,255,0.11); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; }
        .dot-input { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:8px; color:#f4f4f5; font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(239,68,68,0.45); box-shadow:0 0 0 3px rgba(239,68,68,0.07); }
        .dot-input::placeholder { color:#3f3f46; }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#ef4444; color:#09090b; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:rgba(255,255,255,0.06); color:#a1a1aa; border:1px solid rgba(255,255,255,0.08); }
        .dot-btn-ghost:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(239,68,68,0.12); color:#ef4444; }
        select.dot-input option { background:#1a1a1f; }

        /* Forms, notices and status labels shared by the seller screens */
        .dot-page { padding:2rem 2.5rem 3rem; }
        .dot-page-narrow { max-width:880px; }
        .dot-title { font-family:'Syne',sans-serif; font-size:1.6rem; font-weight:800; color:#f4f4f5; letter-spacing:-0.02em; margin:0; }
        .dot-subtitle { font-size:0.78rem; color:#a1a1aa; margin-top:0.25rem; }
        .dot-section-title { font-family:'Syne',sans-serif; font-size:0.85rem; font-weight:700; color:#f4f4f5; margin:0; }
        .dot-panel { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
        .dot-panel-head { padding:1.1rem 1.4rem; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .dot-panel-body { padding:1.4rem; }
        .dot-row { display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; }
        .dot-row-between { justify-content:space-between; }
        .dot-label { display:block; font-size:12px; font-weight:600; color:#d4d4d8; margin-bottom:6px; }
        .dot-help { font-size:12px; color:#a1a1aa; margin-top:6px; line-height:1.45; }
        .dot-error { font-size:12.5px; color:#f87171; margin-top:6px; font-weight:600; }
        .dot-input[aria-invalid="true"] { border-color:rgba(248,113,113,0.75); }
        textarea.dot-input { resize:vertical; min-height:7rem; line-height:1.5; }
        .dot-form-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:1.15rem 1.25rem; }
        .dot-span-all { grid-column:1 / -1; }
        .dot-prefix { position:relative; }
        .dot-prefix > span { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#a1a1aa; font-size:13px; pointer-events:none; }
        .dot-prefix > .dot-input { padding-left:26px; }
        .dot-alert { border-radius:8px; padding:0.75rem 1rem; font-size:13px; margin-bottom:1.25rem; }
        .dot-alert-error { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#fca5a5; }
        .dot-alert-ok { background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.28); color:#86efac; }
        .dot-alert-info { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); color:#d4d4d8; }
        .dot-alert ul { margin:0.35rem 0 0 1.1rem; padding:0; }
        .dot-alert a { color:inherit; text-decoration:underline; }
        .dot-btn-danger { background:rgba(244,63,94,0.1); color:#fda4af; border:1px solid rgba(244,63,94,0.35); }
        .dot-btn-danger:hover { background:rgba(244,63,94,0.18); color:#fff1f2; }
        .dot-btn-block { width:100%; justify-content:center; }
        .dot-btn-small { padding:5px 10px; font-size:12px; }
        .dot-btn:focus-visible, .dot-input:focus-visible, .dot-link:focus-visible, a:focus-visible, summary:focus-visible { outline:2px solid #fcd34d; outline-offset:2px; }
        .dot-btn[disabled] { opacity:0.55; cursor:not-allowed; }
        .dot-link { color:#fcd34d; text-decoration:none; font-weight:600; }
        .dot-link:hover { text-decoration:underline; }
        .dot-muted { color:#a1a1aa; }
        .dot-mono { font-family:'JetBrains Mono',monospace; }
        .dot-status { display:inline-flex; align-items:center; padding:2px 9px; border-radius:100px; font-size:11px; font-weight:600; border:1px solid transparent; white-space:nowrap; }
        .dot-status-draft { background:rgba(161,161,170,0.12); border-color:rgba(161,161,170,0.3); color:#d4d4d8; }
        .dot-status-active { background:rgba(34,197,94,0.12); border-color:rgba(34,197,94,0.28); color:#86efac; }
        .dot-status-scheduled { background:rgba(251,191,36,0.12); border-color:rgba(251,191,36,0.3); color:#fcd34d; }
        .dot-status-ended { background:rgba(59,130,246,0.12); border-color:rgba(59,130,246,0.28); color:#93c5fd; }
        .dot-status-cancelled, .dot-status-pending_review { background:rgba(239,68,68,0.12); border-color:rgba(239,68,68,0.28); color:#fca5a5; }
        .dot-table { width:100%; border-collapse:collapse; }
        .dot-table th { padding:0.8rem 1rem; text-align:left; font-size:11px; font-weight:700; color:#a1a1aa; text-transform:uppercase; letter-spacing:0.08em; white-space:nowrap; border-bottom:1px solid rgba(255,255,255,0.06); }
        .dot-table td { padding:0.9rem 1rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:top; font-size:13px; }
        .dot-table .num { text-align:right; white-space:nowrap; }
        .dot-table tr:last-child td { border-bottom:0; }
        .dot-actions { display:flex; gap:0.4rem; flex-wrap:wrap; }
        .dot-actions form { margin:0; }
        .dot-gallery-main { display:block; width:100%; max-height:420px; object-fit:contain; background:#0d0d10; border-radius:10px; border:1px solid rgba(255,255,255,0.07); }
        .dot-thumbs { display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem; }
        .dot-thumbs a { display:block; border-radius:8px; border:1px solid rgba(255,255,255,0.1); overflow:hidden; }
        .dot-thumbs img { display:block; width:72px; height:72px; object-fit:cover; }
        .dot-image-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:0.9rem; }
        .dot-image-cell img { display:block; width:100%; height:120px; object-fit:cover; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:#0d0d10; }
        .dot-image-cell { display:flex; flex-direction:column; gap:0.5rem; }
        .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }

        /* The shell is a fixed 260px sidebar; below this width it stacks on top instead of covering the page. */
        @media (max-width: 860px) {
            .sidebar { position:static; width:auto; height:auto; border-right:0; border-bottom:1px solid rgba(255,255,255,0.06); overflow:visible; }
            .sidebar::before { display:none; }
            .sidebar-nav { display:flex; flex-wrap:wrap; gap:2px 6px; padding:0 10px 10px; overflow:visible; }
            .sidebar-nav .sidebar-divider, .nav-section-label, .sidebar-footer { display:none; }
            .topbar { position:static; left:0; flex-wrap:wrap; height:auto; min-height:54px; padding:8px 16px; }
            .content-wrap { margin-left:0; padding-top:0; }
            .dot-page { padding:1.25rem 1rem 2rem; }
        }
        @media (max-width: 640px) {
            .dot-form-grid { grid-template-columns:minmax(0,1fr); }
        }
    </style>
    @livewireStyles
    <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>
</head>
<body>
    <x-banner />

    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <span class="material-symbols-rounded">gavel</span>
            </div>
            <div>
                <div class="brand-name">Dot.Auction</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">Auction Platform</span>
                </div>
            </div>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>
            <a href="{{ route('auctions.index') }}" class="nav-item {{ request()->routeIs('auctions.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">storefront</span>
                Browse Auctions
            </a>
            <a href="{{ route('seller.auctions.index') }}" class="nav-item {{ request()->routeIs('seller.auctions.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">sell</span>
                My Auctions
            </a>
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam?->name ?? 'Personal' }}</div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <header class="topbar">
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Auction
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam?->name ?? 'Personal' }}</span>
        <livewire:notification-bell />
        @endauth
        <button
            type="button"
            onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('dot-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');"
            class="topbar-btn"
            title="Toggle dark mode"
        >
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
    </header>

    @livewire('navigation-menu')

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
