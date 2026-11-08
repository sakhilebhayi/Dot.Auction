# R-42 Dot.Auction: completion report

Written 2026-11-09. Brief: improve what exists (design, UX, reliability, security, performance) without inventing features or auction rules. Evidence lives in the documents named in each section.

## 1. Verdict in one paragraph

The auction core was already sound and stayed that way: bids, Buy Now, closing and payment recording held under real parallel load on PostgreSQL. What was wrong was everything around it, and that is what changed. The product now has one design language ("Rostrum") in light and dark across every page, a role-aware home, a bid page that puts bidding first on a phone, honest status words for every lot state, polling-based live bidding that works without a websocket server, and a set of production bugs that SQLite-only CI had hidden (Watch never worked on PostgreSQL, search was case sensitive there, account deletion cascaded into other people's money records, a profile form allowed e-mail takeover). Two independent design juries scored it **7.1 out of 10** both times (Site of the Day average is about 7.2 to 7.6). It is a clear step up from the starting point and a credible product, not yet a standout design; section 9 says what stands between it and the next point.

## 2. Skills

Discovered in `~/.claude/skills`: awesome-design-md, awwwards, canvas-ui, design-md, design-taste-frontend (and v1), dot-brand-experience, emil-design-eng, gsap-core, gsap-frameworks, gsap-performance, gsap-plugins, gsap-react, gsap-scrolltrigger, gsap-timeline, gsap-utils, gstack, hallmark, impeccable, jury, open-design, react-bits, scroll-world, terminal-dark, ui-ux-pro-max.

Used: **hallmark, design-taste-frontend, awwwards, emil-design-eng, design-md (format), impeccable** (the design-system agent and all UI and polish agents), **ui-ux-pro-max** (its checklist only; its stock palette and type suggestions were rejected as the "AI default" look the owner dislikes). The `awesome-design-md` and `open-design` skills were consulted for structure only. The Impeccable detector CLI was run on rendered pages.

Not used, with reasons: canvas-ui, react-bits, scroll-world, terminal-dark (WebGL, scroll-cinema and React effects: wrong for a Laravel/Livewire transactional app), gsap-* (no animation library is needed; motion is CSS), gstack and dot-brand-experience (not design tools for this task), jury (the juries were run as independent agents given screenshots only).

Limitations to record: `ui-ux-pro-max` and `emil-design-eng` have broken symlinks in `~/.claude/skills` (read from `~/Dot/.agents/skills` instead); `npx @google/design.md lint` is blocked in this environment, so `DESIGN.md` follows the format but was not linted by the CLI; the awwwards Next.js scaffolding and the design-taste `draw` script were not applicable to a Laravel app; the Impeccable launcher was not run (DESIGN.md is new here).

## 3. Initial state (audit, `R42_UX_AUDIT.md`, `R42_BACKEND_AUDIT.md`)

Laravel 13 (README said 12), Livewire 3, Jetstream, Reverb (off on the host), Paystack settlements, PostgreSQL in production and SQLite-only CI. 553 tests. 93 authenticated pages audited at 1440 and 390 in light and dark in a real browser.

Classification (UX audit section (b)): **fully functional**: listing create/edit/publish/cancel/delete, photo upload, bidding core, anti-snipe, Buy Now, reserve decision, settlements, Paystack failure handling, operator payments, error pages. **Functional but needs improvement**: dashboard, browse, auction detail, bell, bid form, countdown, profile and teams. **Partially implemented**: real-time bidding (claimed, no polling fallback), dark/light mode, auto-bid (dead engine). **Visually implemented but not connected**: KPI cards, category chips, theme toggle, bell destinations, a nine-tile "AgriTech" suite from another product. **Broken**: awaiting-decision lots badged "Cancelled", profile unreadable in light and buttons invisible in dark, silent empty bid input, no bid ceiling, Watch on PostgreSQL. **Duplicated**: three visual systems, Tailwind loaded twice (CDN and Vite), a hidden legacy nav component, three names for one concept. **Missing but necessary**: a buyer view (my bids, won, watching), outcome messages on ended lots, sort, notification destinations, category provisioning.

## 4. Pages reviewed

Every authenticated route as new user, buyer, leading and outbid bidder, winner, seller, second seller and operator: dashboard, browse (all states and sorts), lot page in every status and viewer, bidding, Buy Now, watchlist, bell and notifications, seller lots list/create/edit/images, reserve decision, settlements and payment return states, operator payments, profile, teams, 2FA, API tokens (feature off), errors 403/404/419/429/500/503, and the guest pages. Final crawls: 120 pages by 5 users at 1440 and 390, light and dark (polish round 1), 154 lot pages by 7 viewers (buyer e2e), 27 pages by 3 roles at 390 and 360 (seller e2e).

## 5. Design improvements (completed)

- **One design system** (`DESIGN.md`, `resources/css/tokens.css`, about 38 components in `resources/views/components/ds/`, `/dev/kit` gallery in local/testing): palette of Chalk and Graphite with Gavel gold, Hammer red, Ring teal and Dusk blue defined once as `light-dark()` roles; Archivo and a mono face for clock and lot numbers, self-hosted; AA contrast verified on 100+ pairs; theme toggle works on every page; reduced motion respected.
- **Signature**: the bid ladder and closing-time rail, drawn only from real data (price steps, your position, time left). No fake activity.
- **Shell**: wide-screen rail, top bar, phone bottom bar (Home, Browse, My bids, Watching, More; List a lot lives in More), skip link, one h1 and a unique title per page.
- **Pages rebuilt**: role-aware Home ("Needs you" first, sections only when there is data, new-user paths, no zeros dressed as results; the AgriTech tiles are gone), Browse (honest status vocabulary, counts, five sorts, filter chips as links, a "Closing soon" row, compact rows on phones: about 1,700 px for twelve lots instead of about 7,400), buyer pages (My bids, Watching, Won), notifications with destinations, the lot page (bid first on a phone, an outcome sentence for every status and viewer, confirm step, outbid one-tap, polite live announcements), seller lots list (tabs with counts, cards on phones), listing form (SAST times, live preview, accessible photo drop zone), settlements and payments (one next action per state), operator payments, Jetstream pages (profile, teams, confirm, 2FA) in both themes, auth pages, an honest welcome page, six error pages, mail.
- **Removed**: Tailwind CDN, Google Fonts, Material Symbols, ui-avatars, the hidden legacy nav component, all inline hex colours.

## 6. Functional improvements (completed)

- Bid form: every refusal printed in words under the field (empty, negative, text, too low with the minimum, more than two decimals, above the ceiling, duplicate, rate limited, closed meanwhile, own lot); `novalidate` so the server message is never hidden by a browser tooltip; confirm dialog stating the bid is binding; a repeat press is not an error.
- Live without Reverb: `BidPanel::snapshot()` polled every 3 to 4 s while the tab is visible, server clock offset so a wrong device clock cannot mislead; measured outbid detection in a second browser in 3.1 to 3.3 s.
- One answer to "can this sale be paid": `SettlementPosition` feeds Home, Won, My bids, Payments, the sale page, the seller list and the lot panel (juror 1 found a contradiction here; juror 2 found a second one; both fixed).
- Status honesty: awaiting-decision, closed-pending-result, sold, not sold, reserve-rejected, cancelled each have their own words.
- Outbid notices collapse to one unread per lot; a Buy Now sale no longer says "outbid" in-app; notices open their stored destination (same-host paths only).
- Pages past the end redirect to the last page; one count on Browse; Watch only while a lot can still be watched.
- Account deletion is blocked while there are obligations and otherwise anonymises (bids, lots and sales kept); copy that claimed false "permanent deletion" rewritten.
- Plain validation wording for every form (12-form sweep test).

## 7. Backend, security and performance (completed)

From `R42_BACKEND_AUDIT.md` (29 findings: 4 High, 10 Medium, 15 Low) and `R42_SECURITY_PERF.md` (17 findings):
- Fixed: watchlist insert crashed on PostgreSQL; search case sensitivity and malformed filters (500s); huge, NaN and INF bid amounts; scheduler overlap locks lasting 24 h; settlement failures swallowed silently (now logged at error level); settlement hand-off skipped when a notice threw; public broadcast leaked bidder names; same-second bid ordering; seven missing foreign-key indexes (bid history on a 100k-bid lot 6.6 ms to 0.06 ms); bell unread count scanned 25k rows (15.8 ms to 0.12 ms); **profile form could set e-mail to anyone's (InfoDot sign-in matches by e-mail: account takeover), now read-only**; draft ids enumerable; CSP now enforced (184 real-Chrome page loads, 0 violations) with connect-src narrowed; per-person limiters on Livewire, search, uploads, reset mail; EXIF stripped and thumb/medium derived images (12-card browse page 55 MB to 0.38 MB); team-invite link injection; long bid token 500; session cookie Secure by default; trusted hosts; bids table append-only at the database level (PostgreSQL delete guard; SQLite guards updates); unsettled-sale repair sweep (`auction:repair-unsettled`); seller notified once of a reserve-not-met lot; dead, unlocked `AutoBidEngine` deleted.
- Proven solid with real parallel processes on PostgreSQL: 40 simultaneous equal bids gave one winner; 60 anti-snipe writers extended the close once; Buy Now vs bids consistent; 8 overlapping settle runs settled 30 auctions once each; 8 racing Paystack payments paid once; the Livewire panel to `BidPlacer` stack holds in five scenarios.
- Performance with 5k auctions, 312k bids, 150k notifications: browse 44 to 48 ms, dashboard 28 to 39 ms, lot page 13.5 ms, the bid poll 2.7 ms (set `CACHE_LIMITER_STORE=file`), `auction:settle-ended` with 550 due in 1.3 s; query counts flat as data grows (`QueryBudgetTest`).
- CI: a `tests-postgres` job and `phpunit.pgsql.xml` now run the suite on PostgreSQL (the baseline had 14 failures there).

## 8. Tests and verification (actual results)

| Check | Result |
|---|---|
| PHPUnit, SQLite | **1114 tests, 1094 passed, 20 skipped, 0 failed** (baseline 553 / 7 skipped) |
| PHPUnit, PostgreSQL 16 (production engine) | **1114 tests, 1107 passed, 7 skipped, 0 failed** |
| Signed-in smoke with route, config and event caches | 30 pages, 0 failed, 0 unlisted authenticated routes (the check that would have caught the Dot.Pulse outage) |
| Browser end to end, buyer side (`tests/e2e/auction/`, `R42_E2E_BUYER.md`) | 9 specs, 1,118 checks, all passing on the tree before the last polish round. After the last polish round b1, b3, b4, b5, b6, b7, b9 were re-run and pass; **b2 (discovery) and b8 (phone and keyboard) were not re-proved**: their selectors predate the new "Closing soon" grouping and the new phone bar (7 and 2 checks fail on selectors, not on product behaviour) and need updating |
| Browser end to end, seller/operator/account/platform (`tests/e2e/auction-b/`, `R42_E2E_SELLER.md`) | 7 of 7 specs, 1,149 checks, 101 axe runs, 0 serious or critical |
| axe-core | 0 serious or critical on every audited page at 1440 and 390, light and dark |
| Impeccable detector | clean on rendered pages (the one repeated finding is a false positive: it reads `var()` font sizes as 10 to 15 px) |
| Design jury 1 (screenshots only) | 7.1 (Design 7.4, Usability 6.8, Creativity 6.8, Content 7.5, Developer 7.3) |
| Design jury 2 (after fixes) | 7.1 (Design 7.4, Usability 6.9, Creativity 6.5, Content 8.0, Developer 7.5) |

Polish after jury 2 (payment story agreement, closed lots on Home, Watch rules, compact phone rows, team settings, validation wording, no-photo state) was verified by tests, axe and screenshots but **not re-scored by a third jury**.

## 9. Completed, partial, unimplemented

**Completed**: everything in sections 5 to 7.

**Partial**: Creativity scored 6.5 to 6.8: the lot page expresses the idea very well, but browse, profile and teams are cleaner than they are distinctive. The after-the-hammer experience (what a winner does next) is honest but thin because the product has no seller-contact or collection step. Dark theme is designed but less covered by the e2e specs than light. Teams: Jetstream roles are shown but nothing in the product reads them.

**Unimplemented, on purpose (owner rules; nothing invented)**: see "Needs owner" below.

## 10. Needs owner (decisions only you can make)

1. **Public browse for signed-out visitors.** A stranger currently sees no stock; both juries ranked this P0. It is an access decision, so it was not built.
2. **What a winner does after paying**: seller contact, collection or delivery, an expected date, a support contact (none exists in config).
3. **Reserve not met**: a decision deadline, what happens at it, and a notice to the high bidder on accept or reject.
4. **Bidding rules**: should a leader be able to raise their own bid or set a maximum (proxy bidding, to be designed inside the lock); the first bid is start plus one step (intended?); bids above the Buy Now price; shill-bid control.
5. **Seller payout tracking** (`paid_out_at`): a seller with a paid sale can currently delete their account.
6. **Category provisioning on production**: nothing creates categories and a lot needs one. Run the new idempotent `php artisan auction:seed-categories` (never automatic) or provide your own list.
7. **Dot.Notify**: add the `auction.reserve_not_met` and a Buy-Now-lost template (the e-mail to a displaced bidder after Buy Now still says "outbid"; the in-app notice is right).
8. **InfoDot-only people have no known password** for password-confirmed actions (delete account, 2FA, sign out other browsers).
9. **Terms** name "BluePin Inc"; the holding company elsewhere is "BluPin". Legal text was not touched.
10. Seller full name visible on the lot page while bidders are masked (O-1); teams and roles: keep, rename or hide; photo reorder and cover; browse density controls; filtered-list page titles; sign-in throttle numbers.

## 11. Production blockers and deploy prerequisites

Nothing has been deployed. Deploy is a manual workflow (`workflow_dispatch`), and merging does not deploy.
- Set the environment keys listed in `docs/R42_OPERATIONS.md`, notably `AUCTION_REALTIME_ENABLED=false`, `CACHE_LIMITER_STORE=file`, `LOG_STACK=daily`, `TRUSTED_HOSTS`, `AUCTION_DISPLAY_TIMEZONE` (default Africa/Johannesburg).
- Run `php artisan auction:seed-categories` (or provision categories) before expecting sellers to list.
- The new `tests-postgres` CI job has not run on GitHub before this PR, and `deploy.yml` gates on CI: read the CI result on the PR before any deploy.
- Run the signed-in pages with `php artisan route:cache` (`sh tests/Support/signed-in-smoke.sh`): it passes here; repeat it on the deployed build.
- Not verified anywhere: real Paystack payment (faked throughout), Reverb websockets (polling mode only), real SMTP and Dot.Notify delivery, Safari/Firefox and real phones or screen readers, production web server behaviour (HTTPS/HSTS, gzip, LiteSpeed Host handling, cron cadence), the "Forgot your password" flow for InfoDot-only people.

## 12. Outstanding defects and risks

Buyer e2e specs b2 and b8 are stale after the last polish round (selector updates needed, section 8). A bidder whose session expires mid-bid reaches a sign-in page that does not say why; non-parties see "Sold" for a lot whose sale was cancelled; Home's "Bid again" for a single outbid lot opens My bids rather than the lot (all recorded in `R42_DECISIONS.md`). Browse tab "Not sold" still counts closed-unsettled lots (flagged); `Display::lotState` depends on a loaded relation and must be eager-loaded on lists; a queued settlement job that fails all retries is repaired within the hour by the sweep rather than instantly; the delete guard on the bid ledger is PostgreSQL-only; two moderate npm advisories remain in a build-time Tailwind typography chain; load tested to 300k bids on one machine.

## 13. Recommendations

1. Decide items 1 to 4 of section 10 first: they are what will move the design score and the winner experience most.
2. Run one real Paystack test-mode payment end to end and one real e-mail through Dot.Notify before launch.
3. After deploy, re-run the cached-route signed-in smoke and the two Playwright suites against the live host with the demo seeder disabled (it refuses in production).
4. Keep PostgreSQL in CI permanently: three of the High findings were invisible on SQLite.
5. Consider proxy bidding as a designed feature rather than reviving the deleted engine.
