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
        :root { --accent: #ef4444; --accent-rgb: 239,68,68; --topbar-h: 54px; --drawer-w: min(86vw, 320px); }
        html { scroll-padding-top: calc(var(--topbar-h) + 12px); }
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
        .topbar { position:fixed; top:0; left:260px; right:0; height:var(--topbar-h); background:rgba(9,9,11,0.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#f4f4f5; flex:1; }
        .topbar-team { font-size:11px; color:#52525b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color:#71717a; cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(255,255,255,0.09); color:#d4d4d8; }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* Content */
        .content-wrap { margin-left:260px; padding-top:var(--topbar-h); min-height:100vh; }

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
        .dot-alert ul { margin:0.35rem 0 0 1.1rem; padding:0; list-style:disc; }
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
        .dot-status-awaiting_payment, .dot-status-initiated { background:rgba(251,191,36,0.12); border-color:rgba(251,191,36,0.3); color:#fcd34d; }
        .dot-status-paid { background:rgba(34,197,94,0.12); border-color:rgba(34,197,94,0.28); color:#86efac; }
        .dot-status-payment_overdue, .dot-status-unmatched, .dot-status-failed { background:rgba(239,68,68,0.12); border-color:rgba(239,68,68,0.28); color:#fca5a5; }
        .dot-status-expired { background:rgba(161,161,170,0.12); border-color:rgba(161,161,170,0.3); color:#d4d4d8; }
        .dot-mode { display:inline-flex; align-items:center; gap:5px; padding:2px 9px; border-radius:6px; font-family:'JetBrains Mono',monospace; font-size:10.5px; font-weight:500; letter-spacing:0.08em; border:1px dashed rgba(251,191,36,0.6); color:#fcd34d; white-space:nowrap; }
        .dot-mode-live { border-style:solid; border-color:rgba(239,68,68,0.6); color:#fca5a5; }
        .dot-table { width:100%; border-collapse:collapse; }
        .dot-table th { padding:0.8rem 1rem; text-align:left; font-size:11px; font-weight:700; color:#a1a1aa; text-transform:uppercase; letter-spacing:0.08em; white-space:nowrap; border-bottom:1px solid rgba(255,255,255,0.06); }
        .dot-table td { padding:0.9rem 1rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:top; font-size:13px; }
        .dot-table .num { text-align:right; white-space:nowrap; }
        .dot-table tr:last-child td { border-bottom:0; }
        .dot-actions { display:flex; gap:0.4rem; flex-wrap:wrap; }
        .dot-actions form { margin:0; }
        .dot-gallery-main { display:block; width:100%; max-height:420px; object-fit:contain; background:#0d0d10; border-radius:10px; border:1px solid rgba(255,255,255,0.07); }
        .dot-cover { display:block; width:100%; aspect-ratio:16/10; object-fit:cover; border-radius:8px; margin-bottom:0.75rem; background:#0d0d10; }
        .dot-thumbs { display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem; }
        .dot-thumbs a { display:block; border-radius:8px; border:1px solid rgba(255,255,255,0.1); overflow:hidden; }
        .dot-thumbs img { display:block; width:72px; height:72px; object-fit:cover; }
        .dot-image-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:0.9rem; }
        .dot-image-cell img { display:block; width:100%; height:120px; object-fit:cover; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:#0d0d10; }
        .dot-image-cell { display:flex; flex-direction:column; gap:0.5rem; }
        .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }

        /* Controls that exist only for the small-screen shell. Hidden at and above 860px. */
        .shell-menu-btn, .shell-close, .shell-backdrop, .shell-mobile-only { display:none; }
        /* The stock Jetstream bar sits under the fixed top bar at every width (its team switcher and
           Log out were unreachable); the same links now live in the sidebar. */
        .shell-legacy-nav { display:none; }
        .table-scroll { position:relative; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-scroll:focus-visible { outline:2px solid #fcd34d; outline-offset:-2px; }
        .dot-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:1.25rem; margin-bottom:2rem; }
        .dot-stat-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:0.85rem; margin-bottom:1.25rem; }
        .dot-module-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:1px; background:rgba(67,70,86,0.15); }
        .dot-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:2rem; }
        .dot-head-actions { display:flex; gap:0.75rem; flex-wrap:wrap; }
        .dot-lot-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:0.9rem 1.6rem; text-decoration:none; border-bottom:1px solid rgba(67,70,86,0.1); transition:background 0.15s; }
        .dot-lot-row > div:first-child { min-width:0; overflow-wrap:anywhere; }
        .dot-lot-row > div:last-child { flex-shrink:0; }
        .dot-filter-form { display:flex; gap:0.75rem; margin-bottom:1.75rem; flex-wrap:wrap; }
        .shell-menu-btn:focus-visible, .shell-close:focus-visible, .topbar-btn:focus-visible, .dot-pill-btn:focus-visible { outline:2px solid #fcd34d; outline-offset:2px; }

        /* Between the shell breakpoint and a laptop width the two-column auction page is too tight; stack it earlier. */
        @media (max-width: 1019.98px) {
            .dot-two-col { grid-template-columns:minmax(0,1fr) !important; }
        }

        /*
         * Responsive shell. Below 860px the fixed 260px sidebar becomes an off-canvas drawer
         * (#shell-drawer) opened from the labelled Menu button (#shell-menu-button) in the top bar.
         * The behaviour lives in the small script at the end of <body>; this is only the layout.
         */
        @media (max-width: 859.98px) {
            .shell-menu-btn { display:inline-flex; align-items:center; gap:6px; min-height:44px; padding:0 14px 0 10px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:#e4e4e7; font:600 13px/1 'Inter',system-ui,sans-serif; cursor:pointer; flex-shrink:0; }
            .shell-menu-btn .material-symbols-rounded { font-size:20px; }
            .shell-close { display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; margin-left:auto; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:#d4d4d8; cursor:pointer; flex-shrink:0; }
            .shell-account, .shell-mobile-only { display:block; }

            .topbar { left:0; padding:0 12px; gap:8px; }
            .topbar-title { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .topbar-team, .topbar-profile { display:none; }
            .topbar-btn { width:44px; height:44px; border-radius:8px; }
            .topbar-btn .material-symbols-rounded { font-size:20px; }
            .content-wrap { margin-left:0; }

            .sidebar { width:var(--drawer-w); height:100vh; height:100dvh; overflow-x:hidden; overflow-y:auto; overscroll-behavior:contain; z-index:60; box-shadow:12px 0 32px rgba(0,0,0,0.5); transform:translateX(-100%); visibility:hidden; transition:transform .22s ease, visibility 0s linear .22s; }
            .sidebar::before { display:none; }
            .sidebar-brand { padding-right:12px; }
            .sidebar-nav { flex:1 0 auto; overflow:visible; }
            .nav-item { min-height:44px; padding:0 12px; font-size:14px; width:100%; background:none; border:0; text-align:left; font-family:inherit; cursor:pointer; }
            .nav-item:hover { transform:none; }
            .sidebar-footer { padding-bottom:max(14px, env(safe-area-inset-bottom)); }
            .shell-backdrop { display:block; position:fixed; inset:0; z-index:50; background:rgba(0,0,0,0.6); opacity:0; pointer-events:none; transition:opacity .22s ease; }
            body.shell-open { overflow:hidden; }
            body.shell-open .sidebar { transform:none; visibility:visible; transition:transform .22s ease, visibility 0s; }
            body.shell-open .shell-backdrop { opacity:1; pointer-events:auto; }

            .dot-page { padding:1.25rem 1rem 2rem; }
            .dot-head { margin-bottom:1.25rem; }
            .dot-kpi-grid { grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; margin-bottom:1.25rem; }
            .dot-kpi { padding:1rem !important; }
            .dot-lot-row { padding:0.9rem 1rem; }
            .dot-stat-grid { grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); }
            .dot-module-grid { grid-template-columns:minmax(0,1fr); }
            .dot-filter-form > * { flex:1 1 100% !important; max-width:none !important; min-width:0 !important; }
            .dot-filter-form > select { flex:1 1 calc(50% - 0.4rem) !important; }
            .dot-btn, .dot-pill-btn { min-height:44px; }
            .dot-btn-small { padding:5px 12px; }
            .dot-input { min-height:44px; font-size:16px; }
            .table-scroll .dot-table { min-width:680px; }
            .table-scroll .dot-table td:first-child { min-width:200px; }
            .table-scroll .dot-actions { flex-wrap:nowrap; }
            .dot-panel-head, .dot-panel-body { padding-left:1rem; padding-right:1rem; }
            /* The notification list is a popover anchored to the bell; on a phone anchor it to the viewport instead. */
            .bell-panel { position:fixed !important; top:calc(var(--topbar-h) + 8px) !important; left:12px !important; right:12px !important; width:auto !important; max-height:calc(100vh - var(--topbar-h) - 24px) !important; max-height:calc(100dvh - var(--topbar-h) - 24px) !important; }
        }
        @media (max-width: 640px) {
            .dot-form-grid { grid-template-columns:minmax(0,1fr); }
        }
        @media (max-width: 479.98px) {
            .dot-kpi-grid > :last-child:nth-child(odd) { grid-column:1 / -1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .live-dot { animation:none; }
            .sidebar, .shell-backdrop, .nav-item, .dot-btn, .dot-lot-row { transition:none !important; }
        }
    </style>
    @livewireStyles
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render ('Alpine.cloneNode is not a function'). --}}
</head>
<body>
    <x-banner />

    <aside class="sidebar" id="shell-drawer" aria-label="Sidebar">
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
            <button type="button" id="shell-close" class="shell-close" aria-label="Close menu">
                <span class="material-symbols-rounded" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav" aria-label="Main">
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
            <a href="{{ route('settlements.index') }}" class="nav-item {{ request()->routeIs('settlements.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">receipt_long</span>
                Payments
            </a>
            @if (auth()->user()?->isPlatformOperator())
            <a href="{{ route('operator.settlement-payments.index') }}" class="nav-item {{ request()->routeIs('operator.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">account_balance</span>
                Payment operations
            </a>
            @endif
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>

            {{-- Phone drawer only: the team switcher and sign-out that the legacy navigation-menu holds
                 (that bar sits under the fixed top bar and cannot be reached). Hidden at 860px and up. --}}
            @auth
            <div class="shell-account">
                <div class="sidebar-divider" style="margin:10px 0;"></div>
                @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
                    <div class="nav-section-label">Team: {{ Auth::user()->currentTeam->name }}</div>
                    <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="nav-item {{ request()->routeIs('teams.show') ? 'active' : '' }}">
                        <span class="material-symbols-rounded nav-icon" aria-hidden="true">groups</span>
                        Team settings
                    </a>
                    @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                    <a href="{{ route('teams.create') }}" class="nav-item {{ request()->routeIs('teams.create') ? 'active' : '' }}">
                        <span class="material-symbols-rounded nav-icon" aria-hidden="true">group_add</span>
                        Create new team
                    </a>
                    @endcan
                    @if (Auth::user()->allTeams()->count() > 1)
                        <div class="nav-section-label">Switch team</div>
                        @foreach (Auth::user()->allTeams() as $switchTeam)
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @method('PUT')
                            @csrf
                            <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                            <button type="submit" class="nav-item" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>
                                <span class="material-symbols-rounded nav-icon" aria-hidden="true">{{ Auth::user()->isCurrentTeam($switchTeam) ? 'check_circle' : 'swap_horiz' }}</span>
                                {{ $switchTeam->name }}
                            </button>
                        </form>
                        @endforeach
                    @endif
                @endif
                <div class="sidebar-divider" style="margin:10px 0;"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="nav-item">
                        <span class="material-symbols-rounded nav-icon" aria-hidden="true">logout</span>
                        Log out
                    </button>
                </form>
            </div>
            @endauth
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

    <div class="shell-backdrop" id="shell-backdrop" aria-hidden="true"></div>

    <header class="topbar">
        <button type="button" id="shell-menu-button" class="shell-menu-btn" aria-expanded="false" aria-controls="shell-drawer">
            <span class="material-symbols-rounded" aria-hidden="true">menu</span>
            Menu
        </button>
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
            aria-label="Toggle dark mode"
        >
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn topbar-profile" title="Profile" aria-label="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
    </header>

    {{-- Legacy Jetstream bar. At 860px and up it sits under the fixed top bar (left as it was);
         on a phone it would render as a stray in-flow bar, so it is hidden there. Its team switcher
         and Log out are in the drawer instead. --}}
    <div class="shell-legacy-nav">
        @livewire('navigation-menu')
    </div>

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
    <script>
        // Off-canvas drawer for the phone shell (below 860px). Plain JS on purpose: it must not depend on
        // Alpine/Livewire having booted. The page behind the drawer is made inert while it is open, which
        // keeps keyboard focus and assistive tech inside the drawer; Escape, the backdrop, the Close button,
        // following a nav link, and growing past the breakpoint all close it, and focus goes back to the button.
        (function () {
            var button = document.getElementById('shell-menu-button');
            var drawer = document.getElementById('shell-drawer');
            var backdrop = document.getElementById('shell-backdrop');
            var closeButton = document.getElementById('shell-close');
            if (!button || !drawer || !backdrop) { return; }
            var wide = window.matchMedia('(min-width: 860px)');
            var isOpen = false;
            var inerted = [];

            function setOpen(next, returnFocus) {
                if (next === isOpen) { return; }
                isOpen = next;
                button.setAttribute('aria-expanded', next ? 'true' : 'false');
                document.body.classList.toggle('shell-open', next);
                if (next) {
                    inerted = Array.prototype.filter.call(document.body.children, function (el) {
                        return el !== drawer && el !== backdrop && !el.inert && !/^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/.test(el.tagName);
                    });
                    inerted.forEach(function (el) { el.inert = true; });
                    (closeButton || drawer).focus();
                } else {
                    inerted.forEach(function (el) { el.inert = false; });
                    inerted = [];
                    if (returnFocus) { button.focus(); }
                }
            }

            button.addEventListener('click', function () { setOpen(!isOpen, true); });
            if (closeButton) { closeButton.addEventListener('click', function () { setOpen(false, true); }); }
            backdrop.addEventListener('click', function () { setOpen(false, true); });
            drawer.addEventListener('click', function (event) {
                if (event.target.closest && event.target.closest('a[href]')) { setOpen(false, false); }
            });
            document.addEventListener('keydown', function (event) {
                if (isOpen && (event.key === 'Escape' || event.key === 'Esc')) {
                    event.preventDefault();
                    setOpen(false, true);
                }
            });
            var onChange = function () { if (wide.matches) { setOpen(false, false); } };
            if (wide.addEventListener) { wide.addEventListener('change', onChange); } else if (wide.addListener) { wide.addListener(onChange); }
        })();
    </script>
</body>
</html>
