# R-42 backend audit: Dot.Auction

Branch `feat/r42-be0`, audited 2026-11-08 against `main` at `86132fc`. Scope: bidding, closing, settlement and
payments, authorisation and privacy, route-cache safety, performance, test health, dependencies. The UX audit and the
demo seeder are on `feat/r42-ux0` and were not touched; no view or seeder file was changed here.

Every claim below was reproduced with a test or a script. "Fixed" means the fix is committed on this branch with a
regression test that fails without it. Nothing was pushed.

How it was run: PHP 8.5.9, Laravel 13.37 (not 12, see F-21), PostgreSQL 16.14 (local), real parallel PHP processes for
the concurrency work, a synthetic dataset of 5,000 auctions, 300,000 bids, 2,000 users, 40,000 watchlist rows and
150,000 notifications for the performance work. Throwaway databases `r42_be0_audit` and `r42_be0_test` were dropped at
the end.

---

## 1. Executive summary

The core of the product is better than its documentation suggests. Bidding, buy-now, closing and payment recording are
all correct under real concurrency on PostgreSQL: 40 processes bidding the same amount produced exactly one winner;
60 processes against an anti-snipe window extended the close once; 8 overlapping settle runs settled 30 auctions
exactly once; 8 processes racing two Paystack payments for one sale paid it once, held the second as a duplicate and
sent exactly two notices. The authorisation matrix has no hole: strangers, team members, operators, losers and sellers
of other auctions get 403/404 everywhere they should.

The defects are at the edges where SQLite (the only database CI uses) differs from PostgreSQL (production), and in
what the product promises versus what the production host can run:

* **The watch button has never worked on PostgreSQL** (F-01). Every "watch this auction" click is a 500, and because
  nobody can watch, the ending-soon notices never go out. 14 existing tests fail on PostgreSQL for this single reason.
  Fixed.
* **Search is case sensitive on PostgreSQL** (F-03): searching "guitar" does not find "Signed Guitar". A non-numeric
  category id, or an array in the query string, is a 500. Fixed.
* **"Live" bidding is not live in production** (F-04). Reverb does not run on the host, there is no polling and no
  client-side countdown, so the price, the countdown and the ended state are frozen until the user reloads. Owner/UX
  decision.
* **Deleting an account destroys other people's money records** (F-02). A buyer who deletes their account deletes the
  paid settlement; a seller who does so deletes every bid other people placed on their auctions. Owner decision.

Counts: 4 High (2 fixed, 2 need an owner decision), 10 Medium (5 fixed, 1 partly fixed, 4 open), 15 Low (2 fixed, the
rest recommendations), plus a short list of product-rule observations. 628 tests now (baseline 553), green on SQLite
and on PostgreSQL.

---

## 2. Findings

Severity is about production impact on PostgreSQL. Effort: S under an hour, M a day, L several days.

| ID | Sev | Area | Finding | Status |
|----|-----|------|---------|--------|
| F-01 | High | Watchlist | `Watchlist::create()` fails on PostgreSQL | Fixed |
| F-02 | High | Data integrity | Account deletion cascades through bids, settlements and sales | Owner decision |
| F-03 | High | Discovery | Search case sensitive on PostgreSQL; malformed filters are 500s | Fixed |
| F-04 | High | Realtime | Bid panel is static in production | Owner / UX |
| F-05 | Med | Bidding | Hostile or huge bid amounts are 500s | Fixed |
| F-06 | Med | Closing | A failing winner notice could skip the settlement; lost listener jobs are never recovered | Part fixed |
| F-07 | Med | Scheduler | Overlap lock lives 24 hours | Fixed |
| F-08 | Med | Observability | Settlement failures were swallowed | Fixed |
| F-09 | Med | Reserve flow | Seller never told; no expiry; bidder never told | Owner decision |
| F-10 | Med | Tests | CI cannot see PostgreSQL behaviour; two flaky tests | Fixed |
| F-11 | Med | Time | Everything is UTC, for users in South Africa | Owner decision |
| F-12 | Med | Dependencies | 5 Composer advisories; Vite/concurrently advisories | Recommendation |
| F-13 | Med | Privacy | Broadcast carried the bidder's full name on a public channel | Fixed |
| F-14 | Med | Performance | Images are served at original size everywhere | Recommendation |
| F-15 | Low | Performance | Foreign keys not indexed | Fixed |
| F-16 | Low | Bidding | Same-second bids shown in unspecified order | Fixed |
| F-17 | Low | Dead code | `AutoBidEngine` is unused and unsafe | Recommend delete |
| F-18 | Low | Dead code | `AuctionItem` can never be created; `auctions.image_path` is dead | Recommendation |
| F-19 | Low | Security | Host header is not pinned (`trustHosts`) | Recommendation |
| F-20 | Low | Security / perf | Runtime CDNs for Tailwind and Alpine; CSP report-only | Recommendation |
| F-21 | Low | Docs | README, wiki, deployment comments are out of date | Recommendation |
| F-22 | Low | Auth | Unused `/api/user` scaffold route | Recommendation |
| F-23 | Low | Payments | Paystack call made inside a DB transaction holding a row lock | Recommendation |
| F-24 | Low | Privacy | Draft ids are enumerable; category counts include drafts | Recommendation |
| F-25 | Low | Consistency | Hard-coded "R" in places that ignore the configured symbol | Recommendation |
| F-26 | Low | Ops | `/up/realtime` is 503 in production; single unrotated log; cron output truncated | Recommendation |
| F-27 | Low | UX | Double-clicking Buy Now shows an error after a success | Recommendation |
| F-28 | Low | Security | Broad `$fillable` on money models (not reachable today) | Recommendation |
| F-29 | Low | UX | Invalid bid text gives no feedback at all | Recommendation |

### High

**F-01 Watchlist insert fails on PostgreSQL (Fixed, 83f4fe7).**
`watchlists` has a composite key and no `id`, but `Watchlist` kept Eloquent's incrementing key, so PostgreSQL got
`insert ... returning "id"` and answered `column "id" does not exist`. Repro: `phpunit -c phpunit.pgsql.xml` before the
fix: 14 errors (`WatchlistTest`, `NotifyEndingSoonCommandTest` x8, `DashboardTest`, `InfoDotTeamSyncTest`,
`NotifyIngestTest` x2). The wiki recorded this on 2026-08-04 as "left unfixed" and it stayed. Fix: `$incrementing =
false` on the model. Regression: `Audit/WatchlistKeyShapeTest` (fails on SQLite too, because it checks the model shape
and that the insert contains no `returning`). Effort S.

**F-02 Account deletion cascades through other people's data (Owner decision).**
`Features::accountDeletion()` is on and `bids.bidder_id`, `auctions.seller_id`, `auction_settlements.{winner,seller}_id`
and `auction_settlements.winning_bid_id` are all `ON DELETE CASCADE`. Reproduced on PostgreSQL with
`DeleteUser::delete()`:

| Who deletes their account | Result |
|---|---|
| Buyer with a **paid** sale | settlement and its events gone; the payment row survives with `settlement_id = NULL`; the seller loses the sale record |
| Leading bidder on a **live** auction | winning bid gone, `current_price` still R300, zero winning rows; the next bidder is asked for R310; the auction will end with no winner |
| Seller with a paid sale and a live auction with bids | both auctions, every bid other people placed, the settlement: all gone; paid money orphaned |

Recommendation (a rule, so yours to set): refuse deletion while the user is a party to an unpaid or paid settlement, a
live or `pending_review` auction, or the leader of a live auction; and change those foreign keys to `restrict`, or
anonymise the user instead of deleting the row (POPIA erasure is usually met by anonymising, and finance records have
retention duties). Effort M. No code change was made.

**F-03 Search and filters (Fixed, 39ad426).**
(a) `title like %q%` is case sensitive on PostgreSQL, so lower-case queries missed capitalised titles; the existing
search test used the exact case and could not see it. (b) `?category=abc` and `?category=99999999999999999999` raised
`invalid input syntax for type bigint` (500) on PostgreSQL. (c) `?q[]=x`, `?category[]=1` were 500s on every database.
(d) `%` and `_` in the query behaved as wildcards. Fix: validated, typed query values; `lower(title) like ? escape ?`.
Regression: `Audit/AuctionDiscoveryInputTest` (16 cases, run on both databases). Effort S.

**F-04 The bid panel is not live where it runs (Owner / UX).**
`docs/deployment.md` sets `BROADCAST_CONNECTION=log` ("Reverb does not run here"). The panel listens for Reverb events
only; there is no `wire:poll`, no ticking countdown (`timeRemaining` is rendered once on the server) and nothing
re-renders the page when the auction ends. In production a bidder sees a frozen price and a frozen "2h 14m" until they
reload or bid. README and wiki promise "real time". Recommendation: `wire:poll.10s` on the panel (it is two cheap
queries: measured 12 queries / 3 ms on the full page) plus a client-side countdown from `ends_at`, and flip the page
to "Ended" locally when it reaches zero. This is a view change, so it was left for the UX work. Effort S to M.

### Medium

**F-05 Hostile bid amounts (Fixed, 701c83d).** `BidPlacer` accepted any finite float and any exponent string. Measured on
PostgreSQL: `INF`, `NAN`, `1e308`, `999999999999999999999` raised `ErrorException`; `10000000000` and `1e15` raised
`SQLSTATE[22003] numeric field overflow`. In the UI, the bid field was a typed `float` Livewire property, so a crafted
request with `abc` caused a `TypeError` and `1e400` produced an unserialisable snapshot (500). The platform's own price
ceiling (R100,000,000) applied to listings but never to bids. Fix: reject non-finite values and anything above the
ceiling with a `BidRejected`; keep the property as text. Regression: `Audit/BidInputHardeningTest` (16 cases).

**F-06 Settlement announcement (Part fixed, 879f993).** After commit, the settler notified the winner first and
dispatched `AuctionSettled` second. A notice that threw left an `ended` auction with a winner, no settlement, no seller
notice, and nothing ever re-announces it (reproduced; same order in the reserve-accept path). Fixed by dispatching the
hand-off first and making the notice best effort. Still open: if the queued `RecordSettlementForPayment` job fails its 3
tries (worker killed, database blip) it lands in `failed_jobs` and the sale has no settlement either. Recommend a
sweep in `auction:settle-ended` that re-dispatches `AuctionSettled` for ended auctions that have a winning bid, no
settlement and no rejected proposal, limited to the last few days so old pre-settlement auctions are not back-filled.
The listener is idempotent (unique `auction_id`), so the sweep is safe. Effort S.

**F-07 Overlap lock (Fixed, 12c9e4c).** `withoutOverlapping()` defaults to a 1,440 minute lock. A `schedule:run` killed
mid-command (shared hosts do this) would have stopped settlement, ending-soon notices and overdue marking for a day.
Now 10 / 10 / 20 / 60 minutes. Regression: `Audit/ScheduleSafetyTest` (fails with "holds its overlap lock for 1440
minutes" without the fix).

**F-08 Swallowed failures (Fixed, 88f06c9).** `auction:settle-ended` printed `Failed to settle auction #n` to stdout and
returned success. The cron line writes stdout to `cron-scheduler.log` with `>`, so it is overwritten every minute: a
permanently failing auction retried every five minutes with no trace. Now `report($e)` (application log) and a non-zero
exit; the ending-soon command reports too. Regression: `Audit/SettleCommandFailureTest`.

**F-09 Reserve-not-met flow (Owner decision).** `ReserveNotMet` is dispatched but has no listener, so the seller is only
told if they happen to open the dashboard. There is no expiry or reminder: a `pending_review` auction stays pending
indefinitely (it cannot be edited, cancelled or deleted, and no one else is told). On accept the bidder gets the win
notice; on reject nobody is told anything, including the high bidder. Needs rules from you: notify the seller, a
decision deadline and what happens at it, and a notice to the high bidder either way. Effort M.

**F-10 CI cannot see PostgreSQL (Fixed, ba1ef78).** `ci.yml` runs SQLite only. That is how F-01, F-03 and F-05 shipped.
Added `phpunit.pgsql.xml` and a `tests-postgres` CI job (Postgres 16 service). Two flaky tests found and fixed while doing
this: `MetricsCollectorTest::test_the_company_is_the_current_team...` depended on PostgreSQL heap order
(`CompanyDirectory` now orders by id, 6ef9430), and a duplicate-slug collision in `AuctionCategoryFactory` that was caused
by my own parallel test leaking rows (also fixed). **The new CI job could not be run here**; first run on GitHub may need
a tweak. Note that `deploy.yml` calls `ci.yml` as its release gate, so a broken job would block deploys until fixed.

**F-11 Time (Owner decision).** `config/app.php` hard-codes `'timezone' => 'UTC'` (no env), the seller form interprets
`datetime-local` input in it, and every page prints times in it. Forms and the settlement pages label it "(UTC)", the bid
panel (`Closing time extended to 14:05:00`, `Bidding opens ... at 06:00`) does not. A seller in Johannesburg who types
18:00 closes the auction at 20:00 local. Do not change `app.timezone` (stored timestamps would shift); keep UTC storage and
add a display zone (`Africa/Johannesburg`, configurable) used for input parsing and output. DST is irrelevant for South
Africa. Effort M.

**F-12 Dependencies (Recommendation; not changed, per CLAUDE.md).** `composer audit`: 5 advisories, all fixed by a patch
update of the same Symfony 8.1 line (`symfony/http-foundation`, `http-kernel`, `routing`, `mime` 8.1.9 to 8.1.11,
`yaml` dev-only via Boost). `composer update --dry-run` shows 10 lock changes, no majors. `npm audit`: 9 findings, all
build/dev tooling (`vite`, `concurrently`, `shell-quote`, `browserslist`); `npm audit --omit=dev` is clean because nothing
ships from npm to the browser at runtime. Majors available: Livewire 4, PHPUnit 13, Tailwind 4 (not urgent).
`@tailwindcss/vite` is installed but unused and mismatched with `tailwindcss` 3.4.

**F-13 Broadcast privacy (Fixed, 2db5169).** The page masks bidders as "Sakhile B." but `BidPlaced::broadcastWith()` sent
the full name on a public channel (`auction.{id}`), readable by any websocket client holding the public Reverb key, signed
in or not. Latent today because Reverb is off, live the day it is switched on. Now masked via `User::maskedName()`;
`Audit/BidBroadcastPrivacyTest` also pins the payload keys. The channel itself is still public (the amounts are on it);
making it a private channel is a decision for when Reverb returns.

**F-14 Images (Recommendation).** Photos are validated and stored well (see section 3) but served at original size
(up to 4 MB, 16 megapixels) in the browse cards and as 72 px thumbnails, six per auction. Serving is cheap (1.5 ms per
request in-process, `Cache-Control: private, max-age=3600`) but there is no `ETag`/`Last-Modified`, so every hour each
image is downloaded again in full. Generate 480 px and 1600 px derivatives at upload (GD is already required) and strip
EXIF, which today keeps GPS data (documented in `docs/deployment.md`). Effort M.

### Low

* **F-15 Indexes (Fixed, b8fa0a0).** PostgreSQL does not index foreign keys. EXPLAIN on the 300k-bid dataset: bid history
  for a 100k-bid auction 6.6 ms to 0.06 ms; a user's bids 5.7 ms to 0.14 ms; `auctions.seller_id` seq scan to index only.
  New migration (idempotent, 300 ms on the dataset, rolls back cleanly) adds seller, category, bidder, `(auction_id,
  created_at, id)`, watchlist auction, settlement winner and seller.
* **F-16 Bid order (Fixed, 2db5169).** `created_at` is whole-second, `bids()` ordered by it alone, so same-second bids came
  back in storage order (oldest first on PostgreSQL, reproduced). `id` now breaks the tie.
* **F-17 `AutoBidEngine` is dead and unsafe (recommend delete).** Zero references in `app/`, `routes/`, `resources/`,
  `tests/` (only the unread `is_auto_bid` column). It takes no lock, runs no transaction, uses float arithmetic, skips every
  guard `BidPlacer` enforces (active window, seller check, minimum, duplicate, anti-snipe, rate limit, token), and sets
  `current_price` below the winning bid's `amount`, which breaks the invariant settlement relies on. `docs/deployment.md`
  already says it is not wired in. **Do not integrate it.** If proxy bidding is wanted it needs its own rules and design
  (who sees the hidden maximum, how it interacts with anti-snipe and reserve), built inside `BidPlacer`'s lock. Delete the
  file; keep the column until that design exists.
* **F-18** `AuctionItem` renders on the auction page but nothing creates one (README advertises multi-item lots).
  `auctions.image_path` is superseded by `auction_images`.
* **F-19** `trustHosts` is not configured, and the Composer advisory "Generated URLs can point to a host chosen by the
  client" applies. Absolute URLs built from the request host: the Paystack `callback_url`, notification links, password
  reset mail. On a cPanel vhost an arbitrary Host rarely reaches the app, so this is defence in depth:
  `$middleware->trustHosts(at: [config('app.url') host])`.
* **F-20** The layout loads `cdn.tailwindcss.com` (the Play CDN, which the Tailwind docs say is not for production, compiling CSS in the
  browser) and Alpine from unpkg, although `public/build` already contains a compiled stylesheet. The CSP is report-only and
  allows `unsafe-inline` and `unsafe-eval`, so it would not stop XSS even if enforced. Output is escaped everywhere
  (`{!!` is used only for markdown the repo owns and the 2FA QR code).
* **F-21 Docs drift.** README and wiki say Laravel 12 / PHP 8.4; the lock is Laravel 13.37 on Symfony 8 (PHP 8.4+),
  `composer.json` says `^8.3`. README says "an auction settlement job: not yet built" and that the won/ending-soon notices
  have no trigger; both exist and run. Wiki front matter says 0.8.0 while its change log reaches 0.9.1; its roadmap still
  lists the settlement job and `reserve.not_met` as open; it names a shared `infodot` database and Meilisearch/Scout, which
  are not the case. `deploy.yml` comments say the queue worker runs "one per minute"; the cron line is `N-59/2`, every two
  minutes.
* **F-22** `routes/api.php` still has the scaffold `GET /api/user`. The Sanctum "stateful hosts" bug that took Dot.Press
  and Dot.Pulse down does **not** reproduce here: with a Host that is not in `sanctum.stateful`, an SSO POST then
  `/dashboard` and `/auctions` returned 200 with the session cookie (curl against a real server; sessions are the
  `web` guard, and Sanctum's guard falls back to it). Only `/api/user` answers 401 for a cookie, and nothing calls it.
  Delete the route or keep it token-only.
* **F-23** `SettlementPayer::start()` calls Paystack inside a transaction holding the settlement row lock (timeout 10 s +
  connect 10 s). A slow Paystack blocks the webhook and callback for that one sale for up to 20 s. Move the HTTP call
  outside the lock (create the attempt, commit, initialise, then update).
* **F-24** A draft answers 403 while a missing id answers 404, so draft ids can be enumerated by any signed-in user. The
  category filter counts include other sellers' drafts and cancelled lots.
* **F-25** `OutbidNotification` ("new price R..."), the Buy Now tile on the auction page and some `number_format` calls
  ignore `auction.currency_symbol`.
* **F-26** `/up/realtime` returns 503 in the documented production config (`BROADCAST_CONNECTION=log`); an uptime monitor
  pointed at it will page forever. `LOG_STACK=single` means one unrotated `laravel.log`; use `daily`. `cron-*.log` is
  overwritten each run. `failed_jobs` has no alert.
* **F-27** `BidPanel::buyNow()` clears the success message on a second (double-click) call and shows "no longer active".
* **F-28** `Auction`, `Bid`, `AuctionSettlement` and `SettlementPayment` have broad `$fillable` (status, prices, winner). No
  request path reaches them (grepped: no `->all()`, `fill($request`, or `unguard`), so this is a hazard for future code only.
* **F-29** `BidPanel::placeBid()` runs `$this->validate()` but the view has no `@error`, so text such as "abc" does nothing,
  visibly. (UX.)

### Product-rule observations (no defect; decisions for you)

* The first bid must be starting price **plus** one increment (R105 on a R100 start). Many sites accept the starting price.
* A bid above the Buy Now price is accepted, so a buyer can pay more than the Buy Now price (intentional per `BuyNowTest`).
* A leading bidder can raise their own bid. The "duplicate amount" rule is redundant with the minimum rule (price never falls).
* Anyone in a seller's team (or a second account) can bid on that seller's lots; there is no shill-bid control.
* An auction past `ends_at` but not yet settled is listed as Active on `/auctions` until the five-minute job runs (bids are
  refused correctly: `isActive()` is time-based). Settlement latency in production is up to 5 minutes for the scheduler plus up
  to 2 for the queue worker before the winner can pay. Deploying puts the app in maintenance mode, which also pauses the
  scheduler and the worker: do not deploy inside an auction's closing window.

---

## 3. What is solid and must be kept

* **`BidPlacer` and `BuyNowPurchaser`.** One transaction, row lock on the auction, price/status/window re-read under the
  lock, events after commit. Proven with real processes (`Audit/ParallelBiddingPostgresTest`, 5 scenarios, mutation-checked:
  removing `lockForUpdate` makes all five fail, and the partial unique index `bids_one_winner_per_auction` then becomes
  the visible backstop). Also ran 3 rounds of 24 processes racing buy-now against bids: always one winner, price 300.00,
  status ended.
* **Idempotency token** lives in the database cache (survives processes and deploys, 10 minute TTL), is claimed inside the
  transaction after the lock and released on rejection. Same user, same token, 6 simultaneous submits: one accepted.
* **Cents arithmetic.** Money is `numeric(12,2)` and integer cents (`Auction::toCents/fromCents` parse strings digit by
  digit); settlements and Paystack amounts are integer cents; the settlement amount, not the request, is what is charged.
* **Closing.** `AuctionSettler` is idempotent under a lock; 8 concurrent `auction:settle-ended` processes settled 30
  auctions (26 ended, 4 `pending_review`) exactly once each, 18 settlements, 18 seller notices, 18 winner notices, no
  duplicates, 4 queue workers, no failed jobs. Auctions that end while the app is in maintenance are caught on the next run.
* **Payments.** Webhook HMAC over the raw body with constant-time compare before parsing; amount, currency, reference,
  metadata and transaction id checked against the settlement; unmatched money kept for an operator; replay and
  callback-versus-webhook races end `paid` once. Eight processes, two payments for one sale: one `paid`, one
  `duplicate_payment` held, one `paid` event row, two notices. No card data is handled (hosted checkout redirect).
* **Authorisation.** `Audit/AccessMatrixTest` pins the routes list and every role (guest, stranger, seller, team admin,
  operator, winner, loser). Settlements are 404 to outsiders. Reserve price never reaches a buyer's page or `toArray()`.
  Operator is a flag, not an email list, and grants no listing powers.
* **Uploads.** Content-sniffed type, full decode, JPEG completeness check, pixel cap, server-generated names, private disk,
  `nosniff`, policy-checked streaming, all-or-nothing batches under a row lock. A 16 MP image decodes in 30-70 ms.
* **Route-cache safety.** The suite was run with `php artisan route:cache` active: everything passed except three
  registration tests, which differ only because `routes/web.php` registers the `/register` redirect conditionally on
  `ALLOW_REGISTRATION` at cache time (a deploy rebuilds caches, so production is consistent). The `operator` alias is in
  `bootstrap/app.php` and the `paystack-webhook` limiter is in `AppServiceProvider`; nothing is registered as a side effect
  of a route file. `Audit/RouteCacheSafetyTest` guards it (mutation-checked).
* **SSO.** The POST hand-off checks Origin, spends the token, regenerates the session, and only follows in-app paths.
* **Listing rules** (`ListingManager`/`ListingTerms`): locked after the first bid, re-checked under lock, cross-field rules in
  one place.

---

## 4. Performance

Dataset: 5,000 auctions, 300,000 bids (hottest auction 100,367), 2,000 users, 40,000 watchlist rows, 150,000 notifications,
1,216 settlements. In-process request timings, signed in, PostgreSQL:

| Page | Server time | Queries | DB time |
|---|---|---|---|
| `/dashboard` (seller) | 63 ms | 15 | 54 ms |
| `/auctions`, `?q=`, `?category=`, `?status=`, `?page=40` | 13-30 ms | 7 | 7-23 ms |
| `/auctions/{id}` (bid panel, history) | 8-14 ms | 12 | 2-4 ms |
| `/seller/auctions` | 9 ms | 5 | 3.5 ms |
| `/settlements` | 6 ms | 5 | 1.6 ms |

No N+1 (images, categories, bidders are eager loaded; nothing repeats more than five times). HTML is 70-100 KB per page
because all CSS is inline. The slowest dashboard query is the per-seller category count (18 ms on the first run), which
the new `auctions.seller_id` index helps. Everything is fast at this size; the indexes in F-15 matter at 10x to 100x.
Browse search is a sequential scan on `lower(title)`; fine to tens of thousands of auctions, then use `pg_trgm`.
Queue and Reverb: production runs `QUEUE_CONNECTION=database` with a cron worker every two minutes and
`BROADCAST_CONNECTION=log`; each bid enqueues a `BidPlaced` broadcast job (and one for an outbid notice) that only writes
to a log, which is pure overhead until Reverb exists. With the log driver the jobs complete (0 failed).

---

## 5. Tests

* Baseline 553 tests, 7 skipped (all Jetstream template tests for features this app disables: registration, API tokens,
  password reset, invitations, 2FA, account deletion; vacuous, harmless). **On PostgreSQL the baseline had 14 failures.**
* Now 628 tests: 616 pass and 12 skip on SQLite (the 7 plus the 5 PostgreSQL-only parallel tests), 621 pass and 7 skip on
  PostgreSQL. Run with random order: SQLite 3 of 3 green; PostgreSQL 2 green after the two flaky fixes in F-10.
* Run on PostgreSQL: `DB_HOST=/tmp DB_USERNAME=$USER DB_PASSWORD= DB_DATABASE=<throwaway> vendor/bin/phpunit -c phpunit.pgsql.xml`.
* Coverage against the brief: auth/SSO, navigation, discovery and filters (was 5 tests, now 21), detail, creation and editing
  (27), bids and validation (20 atomicity, 14 anti-snipe, 15 buy-now, plus 16 input cases), concurrent bids (new, real
  processes), closing (9 + 5), status changes, notifications (bell, outbid, ending-soon, Notify), permissions (new matrix),
  security headers, payments (105). Weakest: the bid panel as a user sees it (no browser test exists; only Livewire
  component tests), error pages, the reserve-not-met path end to end, and any assertion about production configuration
  (Reverb off, cron cadence).
* No coverage driver (Xdebug/PCOV) is installed here, so there is no line-coverage number.

New tests: `tests/Feature/Audit/*` (12 files, 75 tests) and `tests/Support/parallel-bid.php`.

---

## 6. Fixed on this branch

| Commit | Change |
|---|---|
| 83f4fe7 | F-01 watchlist insert on PostgreSQL |
| 701c83d | F-05 bid amounts and BidPanel input |
| 2db5169 | F-13 broadcast privacy, F-16 bid order |
| 879f993 | F-06 settlement announced before the notice |
| 39ad426 | F-03 search and filters |
| 12c9e4c | F-07 overlap locks |
| b8fa0a0 | F-15 indexes |
| 7959a7f | PostgreSQL phpunit config, parallel bidding test, route-cache guard, access matrix |
| 88f06c9 | F-08 failure reporting |
| ba1ef78 | F-10 PostgreSQL CI job |
| 6ef9430, 2192092 | F-10 flaky ordering / leaked fixtures |

## 7. Decisions needed from the owner

1. **Account deletion (F-02):** block, anonymise, or restrict the foreign keys? Until decided, any user can erase sale
   records and other people's bids.
2. **Live bidding (F-04):** polling every 10 s and a client countdown now, or wait for a host that can run Reverb?
3. **Reserve not met (F-09):** notify the seller, set a decision deadline, tell the high bidder.
4. **Display timezone (F-11):** Africa/Johannesburg for input and output?
5. **Delete `AutoBidEngine` (F-17)** and keep proxy bidding as a separate designed feature.
6. **Dependencies (F-12):** approve a patch `composer update` of the Symfony 8.1 packages.
7. **Product rules** in section 2 (first-bid minimum, bids above Buy Now, shill bids).

## 8. Remaining risk and what was not verified

* The new `tests-postgres` CI job has not run on GitHub. The PostgreSQL parallel test needs `proc_open`.
* Nothing was run against the production host: real cron cadence, process kills, `SESSION_SECURE_COOKIE`, PHP version,
  LiteSpeed behaviour with odd Host headers, the live `.env`.
* Paystack was always faked (the repo's own `Http::fake` pattern); no call to the real API, no real webhook delivery.
* No browser run: websockets, the Livewire UI and the CSP were not exercised end to end. Email delivery (Dot.Notify, SMTP)
  was not exercised.
* Load tested to 300k bids and about 60 simultaneous writers on one auction, not beyond. MySQL is unsupported and untested.
* F-06 (lost listener job), F-02 and F-09 remain open and can leave money or a sale without a record.
