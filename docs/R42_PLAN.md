# R-42 Dot.Auction: plan

Inputs: `R42_UX_AUDIT.md` (93 pages, 25 problems), `R42_BACKEND_AUDIT.md` (29 findings), `R42_DEMO.md` (the demo world), the owner brief. Written 2026-11-08.

## Position

The auction core is correct: bids, buy now, closing, anti-snipe and payment recording held under real parallel load on PostgreSQL. What fails the owner's standard is everything around it: three visual systems, a seller-only dashboard shown to everyone, a bid box at the bottom of a phone, misleading statuses, dead controls, a "real time" claim that does not hold on the host, PostgreSQL-only crashes hidden by SQLite-only CI, and inaccessible Jetstream pages. The job is to make what exists work and feel premium, not to add features.

## Principles for every change

1. Establish whether the existing feature works, then improve it. No new auction rules; product-rule questions go to `docs/R42_DECISIONS.md` for the owner.
2. One design system for every authenticated and guest page; no inline hex styles; no CDN at runtime.
3. Every visible control does something real or is removed. Every status is stated in words, never colour alone.
4. A tested change: PHPUnit on SQLite and PostgreSQL, and a real browser run for the flow.
5. Nothing deploys without the owner. Before any deploy, run the signed-in pages with `php artisan route:cache` (Dot.Pulse outage lesson).

## Workstreams

| # | Workstream | Owner agent | Files |
|---|---|---|---|
| W1 | Backend fixes (decisions in R42_DECISIONS.md): anonymise/block account deletion, Johannesburg display time, seller reserve notice, delete AutoBidEngine, Symfony patch, settlement sweep, bid snapshot contract, hardening | bf (running) | app/Services, app/Models, migrations, config, console |
| W2 | Design system and shell: concept, DESIGN.md, tokens, self-hosted fonts, layout, navigation (desktop rail + phone bar), component kit, theme that works everywhere, Jetstream pages (profile, teams, tokens, 2FA, confirm), auth, errors, mail, welcome | shell | resources/css, resources/js/app.js, layouts, navigation, components, auth, errors, vendor/mail, welcome, profile, teams, api, public/fonts, tailwind/vite config, deploy smoke |
| W3 | Dashboard (role-aware: buyer, seller, winner, new user), auctions browse (sort, filters, status vocabulary, cards, watchlist view), notification bell with destinations | ui-discover | dashboard view, auctions/index, new buyer pages, Livewire for them |
| W4 | Auction detail and bidding: bid panel first on phones, outcome states for every status and viewer, leading/outbid/won messaging, confirm step, polling + client countdown on the snapshot contract, buy now, reserve panels | ui-auction | auctions/show, livewire/auctions/* |
| W5 | Seller area, settlements, payments, operator: listing list/form/images, mobile table, reserve decision where the seller looks, settlement pages, operator payments | ui-seller | seller/*, settlements/*, operator/* |
| W6 | QA: browser end-to-end for each role, mobile, accessibility, security, performance; outside design jury twice; polish | later | tests/e2e |

## Prioritised problem list (from the audits)

P0: mobile bid order; "Cancelled" badge on awaiting-decision lots and no outcome states; unreadable profile/teams and no working theme; seller-only dashboard with AgriTech tiles; silent bid form (no confirm, no guard rails, no max); fake "real time". Backend P0: watchlist crash on PostgreSQL, case-sensitive search, account deletion cascade (all handled in W1).
P1: dead notification links; dead KPI cards/chips; thin browse; three visual languages; contrast; page titles and h1; touch targets; render-blocking third parties; seller table on phone.
P2: keyboard/skip link; display font on numbers; screen-reader labels; payment copy repetition; welcome page.

## Classification of the existing product

Fully functional: listing create/edit/publish/cancel/delete, photo upload, bidding core, anti-snipe, buy now, reserve decision, settlements, Paystack failure handling, operator payments, error pages. Functional but needs improvement: dashboard, browse, auction detail, bell, bid form, countdown, profile/teams. Partially implemented: real-time bidding (needs polling), dark/light mode, auto-bid (dead engine, being deleted). Visually implemented but not connected: KPI cards, category chips, theme toggle, bell destinations, AgriTech suite, team badge. Broken: awaiting-decision badge, profile contrast, empty bid input feedback, no bid ceiling (fixed in W1), watchlist on PostgreSQL (fixed in W1). Duplicated: three visual systems, double Tailwind, legacy hidden nav component, three names for settlements. Missing but necessary: buyer view (my bids, won, watching), outcome messages, sort and sold/scheduled distinction, notification destinations, category provisioning check.

## Open questions for the owner (not decided by the build)

Reserve-not-met deadline and bidder notices; first-bid rule (start plus increment); bids above Buy Now; shill-bid control; whether proxy bidding should exist as a designed feature; category provisioning on production.
