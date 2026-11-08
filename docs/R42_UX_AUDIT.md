# R-42 UX audit (logged-in experience)

Audited 2026-11-08 on branch `feat/r42-ux0`, in a real browser (headless Chrome through playwright-core), against the demo world in
`docs/R42_DEMO.md` (8 fictional users, 25 lots in every status, settlements in every state). Every claim below was seen on screen or measured;
what could not be tested is listed at the end. Backend logic was not changed.

**Screenshots** are outside the repo (they are binary and large): `scratchpad/auction-audit-shots/shots/<name>__<cfg>.png`, where `<cfg>` is
`d-dark` / `d-light` (1440 wide) or `m-dark` / `m-light` (390 wide). The machine-readable sweep (status, axe violations, console, failed
requests, overflow, tap targets per page and config) is `results-sweep-<cfg>.json` in the same directory; the interactive flows are in
`flows*.json`. Names below are the `<name>` part.

Important framing fact: **the in-app shell is fixed dark.** The theme toggle changes nothing on app pages (see P0-3), so "light" screenshots of app
pages are identical to dark ones, except Jetstream pages (profile, teams), which flip and break.

---

## (a) Route and page inventory

| Route | Who | Screenshot name(s) |
|---|---|---|
| `/` welcome (guest) | guest | `guest-welcome` |
| `/login`, `/forgot-password`, `/register` (redirects to login), `/terms-of-service`, `/privacy-policy`, `/cookies` | guest | `guest-login`, `guest-forgot`, `guest-register`, `guest-terms`, `guest-policy`, `guest-cookies` |
| `/auctions/{id}` as guest (redirects to login) | guest | `guest-auction-redirect` |
| `/dashboard` | every role | `dashboard-zanele` (new), `-lerato` (buyer), `-sipho` (winner), `-thandi` (seller, reserve decision waiting), `-pieter`, `-naledi` (operator) |
| `/auctions` browse: default, page 2, search hit, search miss, status active / ended / cancelled / scheduled / pending, category | buyer, new | `browse-default`, `browse-page2`, `browse-search-guitar`, `browse-search-empty`, `browse-status-*`, `browse-category-electronics`, `browse-zanele` |
| `/auctions/{id}` detail, by state and viewer | all | `auction-live-leading-bidder`, `-live-outbid-bidder`, `-live-new-user`, `-live-own-listing`, `-live-reserve-not-met`, `-live-ending-soon`, `-live-buynow`, `-live-no-bids`, `-rug-extended-live`, `-scheduled`, `-scheduled-own`, `-ended-winner-unpaid`, `-ended-seller-view`, `-ended-stranger`, `-ended-overdue-winner/-seller`, `-ended-paid-winner`, `-ended-cancelled-sale`, `-ended-no-bids`, `-ended-buynow-winner/-stranger`, `-reserve-pending-seller/-bidder/-stranger`, `-reserve-rejected`, `-cancelled`, `-cancelled-own`, `-draft-own`, `-draft-other-403`, `-404` |
| Bidding states | buyer | `flow-bid-native-validation`, `flow-bid-toolow`, `-empty`, `-decimals`, `-valid`, `-duplicate`, `-selfraise`, `-doubleclick`, `-huge`, `-ratelimit`, `flow-bid-stale-ended`, `flow-buynow-before/-after/-reloaded/-settlements` |
| Watchlist toggle, notification bell | buyer, new | `flow-bell-open`, `flow-bell-empty`, `flow-mobile-drawer` |
| `/seller/auctions` (list) | seller, new | `seller-index-thandi`, `-pieter`, `-zanele` (empty), `-page2` |
| `/seller/auctions/create`, `/{id}/edit` (draft, draft no photos, live no bids, locked by bids, scheduled, ended 403, other's 403) | seller | `seller-create`, `seller-edit-draft`, `-draft-nophoto`, `-live-nobids`, `-locked-bids`, `-scheduled`, `-ended-403`, `-other-403`; flows `flow-seller-create-empty-errors`, `-create-bad-values`, `-edit-new-draft`, `-upload-invalid`, `-after-publish`, `-after-cancel`, `-after-delete` |
| Reserve decision panel (on dashboard) | seller | `dashboard-thandi`, `flow-reserve-accepted` |
| `/settlements` index and `/settlements/{id}` (awaiting, overdue, paid, cancelled; winner and seller; stranger 404) | winner, seller | `settlements-index-*`, `settlement-awaiting-winner/-seller`, `-overdue-*`, `-paid-*`, `-cancelled-*`, `-stranger-404`; payment on: `pay-on-winner-*`, `pay-on-seller-overdue`, `pay-on-seller-cancelled`, `pay-on-gateway-error`, `pay-on-callback`, `pay-on-index-sipho` |
| `/operator/settlement-payments` (+ `?show=attention`), 403 for others | operator | `operator-all`, `operator-attention`, `operator-forbidden`, `pay-on-operator` |
| `/user/profile` (all five Jetstream sections), confirm password, 2FA, delete modal | all | `profile`, `profile-seller`, `confirm-password`, `flow-profile-*` |
| `/teams/create`, `/teams/{id}` | all | `teams-create`, `teams-show`, `flow-team-*` |
| `/user/api-tokens` | all | `api-tokens` (404: the feature is off) |
| Errors | all | `guest-404`, `auth-404`, `auth-403`, `error-429`, `error-500`, `error-500-browse`, `error-503` |

93 pages x 4 configs were swept; every page loads without horizontal overflow at 390 px (good).

---

## (b) Feature inventory

**Fully functional**
- Listing create / edit / publish / cancel / delete with server validation, error summary with links, locked-terms explanation (`flow-seller-*`).
- Photo upload with type validation and the six-photo limit; private streaming of images.
- Core bidding: valid bid, duplicate refused with a clear message, too-low refused with the minimum stated, decimals refused, double-click submits once
  (verified: one row), rate limit engages ("Too many bids. Try again in 31 seconds."), stale page after close refused ("This auction is no longer active.").
- Anti-snipe extension (message shown to the bidder; lot shows "extended 2 times by late bids").
- Buy Now with native confirm, success message, settlement created.
- Reserve decision (accept / reject with confirm), settlement created on accept.
- Settlements pages for every state, seller cancel of an overdue sale, TEST MODE badge, honest "payout is manual" copy.
- Paystack unreachable is handled: "Paystack could not be reached. Nothing was charged." (`pay-on-gateway-error`).
- Operator payments list with attention-first ordering.
- Error pages 403 / 404 / 429 / 500 / 503 exist, on brand, with a way out.
- Empty states on seller list, payments, dashboard "My Auctions", bell, browse search miss.
- Responsive shell: off-canvas drawer with Escape, focus return, inert background (verified); no horizontal overflow anywhere at 390.

**Functional but needs improvement**
- Dashboard (seller-centric for everyone; see P0-4). Browse (no sort, thin filters, thin cards). Auction detail (no outcome states, mobile order). Notification bell (no links). Bid form (silent errors, no confirm).
  Countdown (static text). Profile / teams (works, unreadable in light, invisible buttons in dark).

**Partially implemented**
- Real-time bidding: the page claims it, but with Reverb off nothing updates and there is no polling fallback (`bid-panel.blade.php` has no `wire:poll`).
- Auto-bid: `AutoBidEngine` and `bids.is_auto_bid` exist; nothing calls the engine (grep: referenced only by its own file). The UI offers no proxy bid.
- Dark/light mode: only Jetstream pages react.
- Team invitations: form works; the mail path was not tested.

**Visually implemented but not connected**
- Dashboard KPI cards ("Watchlist 5", "Categories 8") and category chips: look like controls, are `div`/`span` with no link (verified by clicking).
- Dark-mode toggle: class flips, nothing on app pages changes, icon never changes.
- Bell notifications: every one carries a `url` in its data; clicking marks it read and goes nowhere (verified: URL unchanged).
- AgriTech Intelligence Suite (nine "Planned" farming modules) on the dashboard: unrelated to auctions.
- Top-bar team badge looks like a switcher; is a label.

**Broken**
- Lots awaiting the seller's reserve decision (`pending_review`) are badged **Cancelled** in browse (`browse-page2`: KitchenAid mixer, Karoo landscape). Cause: the card's badge falls through to "Cancelled".
- Profile page in light mode: headings, labels and input text are near-invisible (`profile__d-light`); in dark mode the Save / Enable buttons are near-invisible (`profile__d-dark`).
- Empty or negative bid: nothing happens and the previous error stays on screen (the component's `#[Validate]` messages are never rendered).
- A bid of R99,999,999,999 was accepted (SQLite). No ceiling in `BidPlacer`; on PostgreSQL `decimal(12,2)` this would overflow (not testable here).
- "Reserve not yet met" shown on scheduled and ended lots; "Current bid" shown for lots with no bids; "Leading" badge on closed lots.
- `status=active` filter includes scheduled lots; no way to filter scheduled / awaiting decision / sold.

**Duplicated or unnecessarily complicated**
- Three visual systems (see d). Tailwind loaded twice (CDN runtime in the layout plus the Vite build). The legacy Jetstream `navigation-menu` Livewire component is rendered on every page and hidden with `display:none` (`app.blade.php`, `.shell-legacy-nav`).
- `AutoBidEngine` dead code. Inline hex styles throughout `dashboard.blade.php`, `auctions/*.blade.php`, `bid-panel.blade.php` instead of the `.dot-*` classes the seller screens use.
- "Payments" (nav), "Settlements" (URL, code), "Settlement payments" (operator) for one concept.

**Missing but genuinely necessary**
- A buyer view: bids I hold (leading / outbid), items won, watched lots. No watchlist page exists; "Watchlist 5" goes nowhere.
- Category creation: no seeder, migration or screen creates categories, and a listing requires one. On a fresh deploy a seller cannot list (verify against production data).
- Sort (ending soonest, price, newest) and a "scheduled" / "sold" distinction in browse.
- Result messaging on ended lots (sold for X / ended unsold / awaiting seller decision / you won / you did not win).
- A fallback so the countdown and price stay fresh without WebSockets.
- Notification destinations.

---

## (c) Top 25 problems

**P0**

1. **Bid box is the last thing on a phone.** On `auction-live-leading-bidder__m-dark` the bid input sits at y=1347 of a 1761 px page, after photos, description, stats and items (measured). The single most important action is below five screens of content. Why it matters: bidding is the product; most buyers will be on phones.
2. **Awaiting-decision lots are labelled "Cancelled" and ended lots say nothing of the outcome.** `browse-page2`: two `pending_review` lots show a red Cancelled badge. `auction-reserve-pending-bidder`: the leading bidder sees "This auction has ended", "Reserve not yet met" and a "Leading" badge, with no hint that the seller is deciding. Why: false information to the people whose money is involved.
3. **Settings pages are unreadable (light) or hide their buttons (dark); the toggle does nothing elsewhere.** `profile__d-light`: dark text on the dark shell for every heading and label, white inputs with white text. `profile__d-dark`: "Save" and "Enable" are dark-on-dark. `flows5.json`: the toggle flips `html.dark`, body colour is unchanged, icon stays `dark_mode`. Why: account security (2FA, password) screens are the ones users must be able to read.
4. **The dashboard is a seller dashboard for everyone.** `dashboard-lerato` (buyer with 5 watched lots, bids, an outbid notice and a cancelled sale): "Active 0, Total 0, Bids 0, Categories 8", empty "My Auctions", plus the Ending Soon list of other people's lots and nine unrelated farming modules. `dashboard-sipho`: an unpaid and an overdue sale are nowhere on the dashboard. Why: first screen after sign-in tells most users nothing about their own activity.
5. **Bid form fails silently and has no guard rails.** Empty or negative input: no message, old error stays (`flows.json bid.empty`, `bid.negative`). A too-low amount is stopped by the browser's own tooltip (`min` attribute) so the server's helpful "Minimum bid is R3,200.00" is never seen (`flow-bid-native-validation`). R99,999,999,999 accepted and made the next minimum R100,000,000,099 (`bid.huge`). No confirm step on a binding bid. Why: typo or fat-finger cost, and a lot can be wrecked.
6. **"Real time" is a claim, not a behaviour.** Countdown is rendered once (no `wire:poll`, no JS timer); with Reverb off the price and bid list never change until reload; the welcome page says "no refreshing, no polling" and shows "LIVE REVERB BROADCAST". The console warns "No Reverb credentials configured" and "Laravel Echo cannot be found" on every page (92 / 103 occurrences). Why: a stale price in an auction is a wrong decision.

**P1**

7. **Notifications are dead ends.** `flow-bell-open`: each item has a `url` in its payload; click only marks read. Panel caps at 10 with no "see all", last row is clipped.
8. **No "you are leading / you were outbid / you won" on the panel.** `auction-live-leading-bidder`: leader is invited to bid R1,300 against themselves; "Leading" appears on every viewer's list (it is a property of the bid, not of you). A self-raise is accepted (`bid.selfraise`).
9. **Ended, scheduled and cancelled lots reuse live copy.** Scheduled: "Reserve not yet met", "Current bid R3,500.00", "No bids yet. Be the first." (`auction-scheduled`). Ended: "Current bid". Cancelled: "This auction has ended." (`auction-cancelled`).
10. **AgriTech Intelligence Suite on every dashboard** (`dashboard-*`, 9 "Planned" tiles; about 1,000 px of the 390 px dashboard). Leftover from another product; nothing here is on the Dot.Auction roadmap.
11. **Controls that look clickable are not.** KPI cards, category chips, team badge (verified by click). "Watchlist 5" has no page behind it.
12. **Browse is thin.** No sort, no bid count, no reserve or Buy Now cue, no "you are watching / leading" cue, titles truncated by ellipsis, "Current bid" for unbid lots, ended and live mixed in one grid ordered oldest-first, status filter cannot express scheduled or awaiting decision (`browse-default`, `browse-page2`).
13. **Seller list on a phone shows 2 of 6 columns.** `seller-index-thandi__m-dark`: Bids, Price, Closes and the Edit/Publish/View buttons are off-screen to the right (a scrolling region). The row "Needs your decision" has no action; the decision lives only on the dashboard.
14. **Three visual languages.** See (d). Guest, error and welcome pages (gold, Fraunces) do not look like the app (red/amber, Syne), which does not look like Jetstream pages (slate cards).
15. **Contrast.** axe: 726 failing nodes across the desktop sweep (75 of 93 pages). Worst: `#52525b` on `#141416` = 2.38:1 for the small labels "Current bid", "Starting price", "Bid increment"; `#71717a` on `#141416` = 3.8:1 for 32 KPI labels; "Planned" tags 2.36:1; team badge 2.35:1.
16. **Every page is titled "Dot.Auction"** (83 of 93) and the top bar repeats it; 29 pages have no `<h1>` (dashboard and browse use styled divs).
17. **Small touch targets.** At 390 px: "Back to browse" 111x19, "View the auction" 101x15, "Browse all" 57x17, "Manage all" 66x19; the error pages have 26 targets under 44 px. The shell's own buttons are 44 (good).
18. **Render-blocking third parties.** The layout loads the Tailwind CDN script (console: "cdn.tailwindcss.com should not be used in production", 75 pages), two Google font stylesheets and a Material Symbols font on every page. In this sandbox a page took about 10 s to reach `load` (network-dependent, indicative only). If the icon font is slow the ligature names ("arrow_back") show as text.
19. **Seller's own public page is wrong-footed.** `auction-live-own-listing`: a "Watch" button on your own lot, no Edit / Manage link; bidder identity shown only as first name + initial (fine) but nothing about reserve status relative to your reserve.
20. **Buy Now figures format themselves differently.** `show.blade.php` prints `'R'.number_format(...)` while everything else uses `Auction::money()`; the bid field shows `1300`, not `R1,300.00`.

**P2**

21. **Keyboard.** Nine sidebar stops (and Log out) precede any page content on every page; no skip link (`flows5.json keyboard.skipLink=false`). The "Watching" button's focus outline colour equals the page background (`rgb(9,9,11)`), so the focus ring is invisible on it.
22. **Display font hurts numbers.** Syne renders prices and KPI zeros as rings and wide blobs (`dashboard-zanele`: "0" reads as an oval); money elsewhere uses JetBrains Mono. Currency in the largest type is the hardest to read.
23. **Screen-reader labels.** Bell button `aria-label="Notifications"` while the visible text is the count (axe `label-content-name-mismatch` on 67 pages); pagination "Next" has `aria-label` on a span (4 pages); operator table region not keyboard focusable (2 pages); landmark and region warnings on guest pages.
24. **Payment copy repeats and shows "Pay by" when payment is off.** The manual-payout sentence appears up to three times on a seller's payment page; `settlements-index-*` shows "Pay by" dates even when payment is not available (they only exist in the demo because the seeder sets them; check real data).
25. **Welcome page.** In a full-page capture most sections below the fold render empty bands (`guest-welcome`): content is revealed by scroll animation. Not verified with real scrolling; marketing claims (real-time, no polling) are stronger than the app (P0-6).

---

## (d) Visual language, and where it is inconsistent

**In-app shell** (`resources/views/layouts/app.blade.php`, all `<style>` inline): near-black zinc (`#09090b` page, `#0d0d10` sidebar, `#141416` cards), red accent `#ef4444` for nav and primary buttons (text `#09090b` on red), amber/gold `#fcd34d` for prices and links, status pills green/amber/blue/red. Type: Syne 700-800 for titles and prices, Inter 14 px body, JetBrains Mono for money and tables. Icons: Material Symbols Rounded as font ligatures. Radii 8 / 12 / pill; panel = 1 px 7 percent white border. Spacing: ad hoc rem and px in inline styles.

**Inconsistencies (concrete)**
- `layouts/guest.blade.php`, `errors/_ecosystem-layout.blade.php`, `welcome.blade.php`: gold `#f1c62e` + red `#d71016` on warm ink `#17110f`; Fraunces / Work Sans / IBM Plex Mono. A different brand from the logged-in shell.
- Jetstream views (`profile/*`, `teams/*`, `components/*.blade.php`): Tailwind `dark:` classes (white or slate cards) inside the always-dark shell. The cause of P0-3.
- Seller / settlements / operator screens use the `.dot-*` classes from the layout (good); dashboard, browse, auction detail, bid panel, bell, reserve panel use inline hex styles. Two ways to build the same card.
- Buttons: `.dot-btn-primary` (red), `.dot-pill-btn` (green and rose pills in the reserve panel), the dashboard's own pill links, `x-button` (Jetstream) all differ in radius, weight and colour.
- Links are amber (`.dot-link`) on seller pages but plain grey on detail pages ("Back to browse").
- Tailwind is loaded twice: CDN script with `preflight:false` and the Vite build.

---

## (e) Mobile findings (390 px)

- No horizontal page overflow on any of the 93 pages (the shell work holds).
- Bid panel is at the bottom of the detail page (P0-1). Make the price and bid input reachable at the top or in a sticky bar.
- Seller table hides 4 of 6 columns behind a sideways scroll (P1-13). Card rows would suit phones.
- Dashboard: five KPI cards stack to three rows, then the nine-tile farming suite adds about 1,000 px.
- Detail page title in Syne wraps to two or three lines at 1.6 rem.
- Menu button and drawer behave well: labelled, 44 px, Escape closes, focus returns to the button (verified). The notification panel can stay open behind the drawer.
- Inputs are 16 px (no iOS zoom). The bid field and Place Bid button share one row with a 24 px prefix; fine.
- 2 to 4 px-tall inline links (P1-17).

## (f) Accessibility findings

axe-core, 93 pages x 2 desktop/mobile dark sweeps: `color-contrast` (serious, 726 / 621 nodes), `label-content-name-mismatch` (serious, bell, 67 pages), `page-has-heading-one` (29 pages), `region` (guest pages), `aria-prohibited-attr` (pagination, 4), `scrollable-region-focusable` (operator tables on mobile, 2), `landmark-one-main` (3 guest pages). Also: no skip link; focus ring invisible on the Watch button; native browser validation bubbles are the only error for a too-low bid; form errors in the bid panel use `role="alert"` (good) but empty/negative input produces none; status pills are text, not colour only (good); every seller form field has a label, help text and `aria-describedby` (very good); modals and the drawer manage focus (good). Reduced-motion is honoured by the shell.

## (g) First login, role by role

- **New user (zanele).** Lands on the dashboard: five zeros, "Ending soon" lots that are not hers, eight category chips with 0, an empty "My Auctions" with a clear List-an-auction button, then the farming suite. Nothing says "browse and bid" versus "sell", and nothing explains that she has no activity. Empty states elsewhere are clear. Not clear.
- **Buyer (lerato).** Same dashboard, still zeros although she has bids, a won lot, a cancelled sale and five watched lots. The bell is the only place that shows what happened. Not clear.
- **Winner with an unpaid sale (sipho).** Dashboard shows nothing about money owed. "Payments" in the sidebar has no badge. The Payments list and sale page are clear and good once found. Not clear until he finds Payments or the bell.
- **Seller (thandi).** Clearest: "Awaiting Your Decision" appears first with Accept / Reject and a confirm; the My Auctions table follows. Weak points: the Ending Soon list shows other sellers' lots; Needs-your-decision rows elsewhere have no action.
- **Second seller (pieter).** Same, and clear.
- **Operator (naledi).** Gets the seller dashboard; the only difference is a "Payment operations" sidebar item. No count of payments needing attention on arrival; the page itself is clear (red banner, attention filter).

## (h) Good and must be kept

- Seller listing form: field component with label, help, error and `aria-describedby`; error summary with jump links; the locked-terms explanation; "saves a private draft" language.
- Honest payment and payout copy; the TEST MODE badge; the "Nothing was charged" gateway message.
- Reserve confidentiality (buyers see only met / not met) and masked bidder names.
- Bid idempotency, rate limiting, anti-snipe message and "extended n times" note.
- Native confirm on Buy Now, Accept, Reject, Cancel sale, Delete draft.
- Settlement pages (single-column, plain, status pill, one action) are the best screens in the app and the model for the rest.
- Responsive shell and drawer behaviour; status-pill system; empty-state copy on seller and payment lists.
- Error pages' tone and "ecosystem" footer; demo placeholder images are clearly labelled.
- Private image streaming with `scopeBindings` and policy checks.

---

## Not tested

- The 419 page: Laravel 12's origin-aware CSRF let a same-origin POST without a token through, so no browser reproduction (view exists).
- PostgreSQL behaviour (the huge bid, concurrency); the audit ran on SQLite.
- Real-time updates (Reverb is off; whether Echo would update the panel and bell was not exercised).
- Paystack redirect, webhook and callback with a real test key; only the unreachable-gateway path and the no-attempt callback path were seen.
- Email delivery (team invitations, Dot.Notify), InfoDot SSO sign-in, completing 2FA, the account-delete confirmation (modal opened only).
- Page-load timings on a real network (the sandbox network made third-party assets slow; treat the 10 s as indicative).
- Light-mode captures exist for all pages; the desktop-dark guest 404 was captured in a later pass. The `error-419` capture file shows the settlement page, not a 419 page (see first bullet).
