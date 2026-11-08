# R-42 security and performance pass: Dot.Auction

Branch `feat/r42-sp`, 2026-11-09, from trunk after the backend audit, the shell, discover and seller workstreams. The auction
detail page (`auctions/show.blade.php`, `livewire/auctions/*`, `app/Livewire/Auctions/*`) was still being merged on another
branch and was **not edited**; it was attacked and measured as it stands on trunk, and what it must adopt is listed in section 6.

Everything below was reproduced with a test, a script or a real browser. "Fixed" means a commit on this branch with a test that
fails without it (mutation-checked for the ones marked "m"). Nothing was pushed or deployed.

How it was run: PHP 8.5.9, Laravel 13.37, Livewire 3.8.10, PostgreSQL 16 (local socket) for everything that touches the
database, real parallel PHP processes for concurrency, Chrome 157 driven by `playwright-core` (installed in a throwaway folder
outside the repo) against `php artisan serve` with six workers and the demo world, and a synthetic dataset of 5,000 auctions,
312,493 bids (one lot has 100,000), 2,000 users, 48,059 watch rows, 150,000 notifications (one user has 25,075) and 1,250 sales,
plus 500 auctions due to close. Throwaway databases (`r42_sp_test`, `r42_sp_demo`, `r42_sp_perf`) were dropped at the end.

Suite: **949 tests on SQLite (929 pass, 20 skipped) and 949 on PostgreSQL (942 pass, 7 skipped)**; trunk baseline was 840 / 13.
The skips are the Jetstream template tests for disabled features, the parallel-process tests (PostgreSQL only) and the bid
ledger's delete guard (a PostgreSQL trigger).

---

## 1. Findings

Severity is about production impact. "Test" names the file in `tests/Feature/Security/` unless another path is given.

| ID | Sev | Where | What an attacker or a measurement showed | Fix | Test |
|----|-----|-------|------------------------------------------|-----|------|
| SP-01 | **High** | Profile form (Jetstream) + InfoDot sign-in | A signed-in person could change their e-mail address to any address that had not signed in yet. InfoDot sign-in matches people by e-mail, so the real owner of that address would later be signed into the attacker's existing account (pre-hijack; the attacker keeps their open session and sees what the victim does). | The address is read-only on the form and ignored by `UpdateUserProfileInformation`; it changes in InfoDot. | `LivewireTamperTest::test_the_profile_form_cannot_be_used_to_grant_rights_or_move_the_account_to_another_email` (m), `ProfileInformationTest` |
| SP-02 | Med | `AuctionPolicy::manage/update`, `AuctionSnapshot` | Drafts are meant to look absent (404). The edit, publish, cancel, delete and photo routes answered a stranger **403** for a real draft and 404 for a missing id, so draft ids could be counted. The polling snapshot returned a draft's price and status to anyone. | Those routes answer 404 for somebody else's draft (policy returns `denyAsNotFound`, both form requests keep it); the snapshot reports `missing` for a draft unless the viewer is its seller. | `RouteSweepTest::test_seller_screens_and_actions_belong_to_the_seller_alone` (m), `LivewireTamperTest::test_a_snapshot_of_somebody_elses_draft...` (m) |
| SP-03 | Med | `SecurityHeaders`, `.env.example` | The CSP was report-only, and `connect-src` allowed **any** `https:` and `wss:` host, so a script that reached a page could have sent what it reads anywhere. | Enforced by default (`SECURITY_CSP_REPORT_ONLY=true` still switches it to report-only). `connect-src` is `'self'` plus the Reverb host only when real-time is on, plus anything named in `SECURITY_CSP_CONNECT_SRC`. **Enforced policy run on 184 page loads in Chrome (2 widths, 5 roles, guest pages, bell, theme switch, a bid placed): 0 violations.** | `SecurityHeadersTest` (3 tests), `CookieAndSessionTest` |
| SP-04 | Med | Livewire endpoint, search, uploads, reset mail | No ceiling anywhere on `/livewire/update` (the endpoint the new poll hits), text search (a scan on the title), photo uploads (decode and re-encode) or the password-reset e-mail (`/forgot-password` could be aimed at a stranger's inbox). | Per-person limiters: Livewire 300/min, search 60/min, listing writes 60/min, uploads 20/min, reset mail 5/min per address and e-mail, other Fortify forms 60/min. The existing bid, Pay, Cancel, callback, webhook and CSP limits are pinned by a test. | `RateLimitAndCsrfTest` (7 tests) |
| SP-05 | Med | Photo serving | Photos left the server at original size (4 MB, up to 16 MP) and **with their EXIF block, which carries the GPS position of the seller's home**. Browse cards, lists and 72 px thumbnails all downloaded the original (audit F-14). | Re-encoded copies at `thumb` 480 px, `medium` 1200 px and `large` 2000 px (WebP, JPEG fallback), made at upload and on first request for old photos, EXIF orientation applied then every metadata block dropped, one-month private cache, ETag and 304, same authorisation route (`?size=`). Section 4 has the numbers. | `ImageVariantsTest` (12 tests, EXIF marker and orientation checked byte for byte) |
| SP-06 | Med (perf) | `notifications`, `bids` | The bell counts unread notifications on **every page and every poll**; the only index was `(notifiable_type, notifiable_id)`, so a person with 25,000 notifications scanned all of them (15.8 ms for the count, 11.1 ms for the inbox). "My bids" looked up top bids and "do I lead" with a bitmap AND per lot. | Partial index on unread rows, a time-ordered notifications index, and `bids (bidder_id, auction_id, amount)`. Count 15.8 to 0.12 ms, inbox 11.1 to 1.9 ms. | `QueryBudgetTest`, EXPLAIN in section 4 |
| SP-07 | Low | Team invitation mail | The team name is typed by a stranger and the mail goes out in this app's name; `[Verify your account](https://evil.example)` became a link. | Markdown characters in the name are escaped. | `OutputSafetyTest::test_markdown_mail_does_not_turn_a_team_name_into_a_link` (m) |
| SP-08 | Low | `BidPlacer` idempotency key | The browser-supplied submission token became a cache key; with the database cache (key column 255) a 10 kB token was a 500. | The token is hashed (semantics unchanged). | `LivewireTamperTest::test_hostile_values_in_the_bid_panels_own_fields...` (m, PostgreSQL) |
| SP-09 | Low | `AuctionImageController` | A photo row whose stored path was tampered with raised Flysystem's `PathTraversalDetected` (500). | Any read problem is a 404. | `RouteSweepTest::test_a_photo_cannot_be_read_through_another_lots_url_or_with_a_traversing_id` |
| SP-10 | Low | `config/session.php` | `SESSION_SECURE_COOKIE` defaulted to unset: the session cookie was not `Secure` unless somebody remembered to set it. | Follows `APP_URL`: `https://` means Secure (an explicit value still wins). HttpOnly and SameSite=Lax were already right. | `CookieAndSessionTest` |
| SP-11 | Low | `config/filesystems.php` | Laravel's local-disk route `GET/PUT /storage/{path}` was registered (signed URLs only, nothing here uses them). | `serve => false`: the route is gone. | `RouteSweepTest::test_the_public_allowlist...` |
| SP-12 | Low | `config/livewire.php` | Livewire's temporary upload (profile photo) accepted any file up to 12 MB into a temp folder. | jpg/png, 1 MB, 20 a minute, the same rule the photo is validated against on save. | `UploadAttacksTest::test_the_temporary_upload...` |
| SP-13 | Low | `SettlementPayer` | The Paystack `callback_url` (where the payer is sent after paying) was built from the request's Host header. `TRUSTED_HOSTS` already refuses forged hosts in production; this is the second layer. | Built on the `APP_URL` host. | `PaymentSafetyTest::test_a_forged_host_header...` (m) |
| SP-14 | Low | `ViewerActivity` | Open bids, bids waiting on a seller, sales to pay, late buyers and reserve decisions were unpaged: one busy account could make a page of hundreds of cards (My bids is already 181 kB for 61 live lots). | Ceiling of 120 rows per list (`ViewerActivity::SAFETY_CEILING`). | `QueryBudgetTest::test_pages_that_are_not_paged_stop_at_a_ceiling` |
| SP-15 | Low | `bids` table | Append-only was a convention. A bug or a hand-run statement could rewrite an amount, a bidder, a time, or delete a bid. | Database triggers (PostgreSQL: update and delete; SQLite: update): only `is_winning` may change; a bid cannot be deleted while its auction exists (the cascade from a deleted draft still works). | `BidsAreAppendOnlyTest` (5 tests, SQL level) |
| SP-16 | Low | Settlement hand-off, schedule, categories | The queued hand-off logged nothing of its own when it gave up (only `failed_jobs`); `failed_jobs` and reset tokens only grew; **a deployment with no categories cannot list anything** (the category is required) and nothing said so. | `RecordSettlementForPayment::failed()` logs at error level with the auction id; `queue:prune-failed` and `auth:clear-resets` scheduled daily; `auction:seed-categories` (idempotent, `--dry-run`, `--check`), and an error log line when a seller opens the form with no categories. | `OperationsTest` (7 tests) |
| SP-17 | Low (perf) | Rate-limit store | On the database cache each counted request costs six queries (a Livewire call went from 6 to 12 queries). | `CACHE_LIMITER_STORE=file` moves the counters to files: 12 back to 6 queries, 4.0 to 2.7 ms per poll. Set it in production. | measured; `docs/R42_OPERATIONS.md` |

### Observations (not defects, or not mine to decide)

* **O-1** The lot page prints the seller's full name ("Seller: Sandra Seller") while bidders are masked to "Sandra S.". A marketplace normally names the merchant; if the owner wants it masked, `User::maskedName()` is one call in `auctions/show.blade.php` (not edited).
* **O-2** The auction page logs "Laravel Echo cannot be found" in the console (14 of 14 lot pages): the bid panel's `#[On('echo:auction.{auction.id},.bid.placed')]` registers even when real-time is off. The bell already handles this with `usesEcho()`. Belongs to the file ui-auction owns.
* **O-3** A bid of `1e3` is accepted as 1,000.00 (valid number syntax). Not a hole (the minimum and ceiling still apply, and the confirm step in the new panel will show the amount); noted because the audit's input list called exponents hostile.
* **O-4** Search (`lower(title) like '%q%'`) is a sequential scan: 5,000 lots, 45 ms page. Fine to tens of thousands; then `pg_trgm` (needs the extension on the host). "Most bids" sorting counts bids per row; a 100,000-bid lot costs about 7 ms every time it appears in a list. A stored counter would fix it but touches `BidPlacer`, so it was left.
* **O-5** `/operator/settlement-payments` orders by `CASE WHEN status = 'unmatched'`, which cannot use an index; irrelevant below tens of thousands of payments.
* **O-6** `form-action` is not in the CSP because the Pay button posts to this app and is answered with a redirect to Paystack's checkout, which `form-action 'self'` would block in Chrome. `script-src` keeps `'unsafe-eval'` because Livewire's bundled Alpine compiles directive expressions (a known, permanent constraint). `style-src-attr 'unsafe-inline'` stays for the design system's `--p` custom properties.
* **O-7** An erased account's e-mail address is released (replaced by `former-member-N@deleted.invalid`), so the same person can come back through InfoDot as a new account with no history. Bids, sales and the "Former member" name stay on the old row. There is no moderation or ban list in this app, so this cannot be used to dodge one; if one is added, key it on the InfoDot identity, not the e-mail.
* **O-8** The checkout redirect only requires `https://`; it does not pin the host to `paystack.com`. The address comes from Paystack's own response over a pinned HTTPS base URL, so only a compromised Paystack could abuse it; pin it if the owner wants belt and braces (the cost is a broken payment if Paystack changes its checkout host).
* **O-9** No page measured loads per row (section 3), but each still runs 14 to 24 queries, about half of them the session, the user, the team, the bell and the cache, which every authenticated page pays.

---

## 2. What was attacked and held

* **Authorisation, every route, every person.** `RouteSweepTest` walks every registered route (76 in the local environment). Each is either on a reviewed public allow-list (with a stale-row check) or sends a guest to sign-in / 401 / 403. Then a world with a stranger, the lot's seller, another seller, a leading bidder, an outbid bidder, a member of the seller's team, the winner, a loser, an operator and an erased account. Draft lots and their photos: 404 to everyone but the seller, identical to a missing id. Lot-page HTML for every non-seller never contains the reserve (a distinctive number is searched for), another person's e-mail, or another bidder's full name. Buyer pages (`/bids`, `/watchlist`, `/won`, `/notifications`) list only the viewer's own rows. A sale is invisible (404) to everyone but its two parties; only the winner pays, only the seller cancels, a forbidden request never reaches the gateway; the operator page is 403 for everybody else. A fingerprint of the auction, bid, sale, payment, photo and watch tables is unchanged after every forbidden request.
* **Livewire tampering.** `auction.*` and `proposal.*` model fields cannot be written from the browser ("Can't set model properties directly"); `WatchToggle` and the bell's `userId` are locked; the bell cannot open or read another person's notification; only the lot's seller can decide a reserve; the profile form cannot grant operator rights or switch team. Text written into the bid panel's own message fields is escaped.
* **Open redirects.** Notification addresses (bell and `POST /notifications/{id}/open`): 15 hostile values (`javascript:`, `//host`, `\\host`, CRLF, tab, scheme tricks, look-alike hosts) all end on this site. The sign-in deep link, the dev login `to`, the Paystack return (query string ignored) and `redirect` parameters are path-only.
* **Bid integrity on the final stack.** `ParallelLivewireBiddingPostgresTest` drives the real bid panel (Livewire, rate limiter, idempotency token on the database cache, `BidPlacer`) from parallel PHP processes: 16 people with the same amount, one winner; one person with one token in 8 tabs, one bid; a late burst moves the close once; Buy Now against 12 bidders, one winner at the Buy Now price; a 20-way mixed race leaves a strictly increasing ledger and one winning row. `BidPlacer` rules were not touched.
* **Privacy of bid history.** `BidHistoryExposureTest`: to the seller, a bidder, a stranger and an operator alike the panel shows "Thabo N." and an amount; no surname, e-mail, id or reserve; the Livewire snapshot sent to the browser carries none of them either. The polling snapshot has a pinned key list.
* **XSS.** `OutputSafetyTest` plants `"><img src=x onerror=alert(1)><script>alert(1)</script>` in seller, bidder, winner, operator and team names, category, lot title, description (with a `javascript:` Markdown link and `<b>`), item rows, settlement notes and nine kinds of notification, then opens 18 pages as 4 kinds of viewer plus the bell: 0 raw payloads. A guard checks the title does appear, escaped. The only `{!! !!}` in views are repo-owned Markdown, icon markup and the 2FA QR code.
* **Uploads.** `UploadAttacksTest` (15 cases): SVG with script, SVG/HTML/PHP/PDF/ZIP named as images, GIF, BMP, empty, header-only JPEG, a PNG declaring 40,000 x 40,000 px (refused on the header in under 2 s), a JPEG with a PHP script appended (accepted as a picture, **served only as a fresh re-encode with no appended bytes**), traversal and script in the client file name (never a path, never rendered), a client that lies about the type (the real type is recorded). Nothing lands on the public disk.
* **CSRF.** Every state-changing route is in the `web` group with the forgery check, except three reviewed ones (InfoDot sign-in: Origin check plus single-use token; CSP reports; Paystack webhook: HMAC) plus the framework's `/broadcasting/auth`; a stale-row check keeps the list honest.
* **SSRF / header injection.** The only outbound HTTP goes to configured base URLs (Paystack, InfoDot, Notify, Analytics); no user value becomes a URL. User text reaches a response header nowhere except redirects, which are path-checked and CRLF-checked.
* **Mass assignment.** `$fillable` of every model is pinned; Bid, sale and payment rows have none; hostile attribute bags set nothing; no request path hands raw input to a model.
* **Other.** `/dev/*` routes are absent in `production` and `staging` (checked in fresh processes); the API token page is 404 and `/api/user` is 401 for an unknown origin; deleting an account is refused with unpaid sales, anonymises otherwise, keeps every bid, and the old address cannot sign in; a team invitation needs its signed link; HSTS, `nosniff`, `X-Frame-Options`, referrer and permissions policies, trusted hosts (400 for a forged host, `/up` exempt, verified under cached config by `signed-in-smoke.sh`: 30 pages, 0 failed), session cookie HttpOnly and SameSite=Lax.

---

## 3. Performance (PostgreSQL, 5k auctions, 312k bids, 150k notifications)

In-process requests signed in through real session cookies, warm, 40 samples after 2 warm-ups, the cache emptied before each call.
Machine shared with other agents' processes, so read differences under about 10 percent as noise. "Before" is this branch with
the three new indexes dropped; "after" has them.

| Page | p50 ms before | p50 ms after | p95 before | p95 after | queries | DB ms before | DB ms after | HTML bytes |
|---|---|---|---|---|---|---|---|---|
| dashboard (seller, 25 lots) | 29.1 | 27.9 | 40.3 | 35.0 | 23 | 12.0 | 11.4 | 56,478 |
| dashboard (buyer, heavy) | 43.3 | 39.4 | 50.8 | 46.7 | 22 | 21.0 | 17.4 | 78,310 |
| dashboard (new user) | 32.4 | 32.7 | 43.9 | 39.4 | 20 | 14.1 | 14.6 | 65,162 |
| browse `/auctions` | 47.5 | 43.9 | 55.0 | 50.9 | 14 | 23.3 | 19.9 | 85,913 |
| browse `?q=guitar` | 47.9 | 44.6 | 58.4 | 56.2 | 22 | 23.3 | 20.5 | 84,711 |
| browse `?category=5` | 34.3 | 27.5 | 43.4 | 32.5 | 14 | 17.3 | 11.9 | 66,154 |
| browse `?state=ending` | 43.3 | 35.9 | 56.2 | 47.3 | 14 | 20.3 | 14.8 | 81,415 |
| browse `?sort=bids` | 55.9 | 48.4 | 66.1 | 56.9 | 14 | 37.3 | 30.2 | 65,585 |
| browse `?page=40` | 46.2 | 43.5 | 58.7 | 53.5 | 14 | 22.1 | 18.7 | 85,038 |
| lot page (hot, 100k bids) | 20.0 | 13.5 | 27.2 | 15.1 | 17 | 9.4 | 4.0 | 48,334 |
| lot page (ordinary) | 20.1 | 13.8 | 28.9 | 17.9 | 17 | 9.6 | 4.1 | 47,322 |
| seller list | 32.7 | 32.2 | 36.4 | 38.1 | 17 | 14.2 | 15.0 | 77,641 |
| seller list `?state=live` | 20.5 | 20.4 | 26.0 | 23.6 | 15 | 9.6 | 9.2 | 37,825 |
| My bids (161 bids) | 78.7 | 63.3 | 116.0 | 70.0 | 13 | 33.6 | 22.2 | 180,704 |
| Watching | 39.2 | 24.4 | 66.1 | 30.3 | 11 | 17.8 | 5.9 | 73,117 |
| Won | 22.2 | 14.5 | 31.3 | 19.6 | 14 | 12.0 | 5.1 | 36,342 |
| settlements (seller / winner) | 12.1 / 20.9 | 11.1 / 19.4 | 12.9 / 31.0 | 16.2 / 22.5 | 9 / 11 | 2.5 / 3.8 | 2.4 / 3.5 | 33,243 / 64,946 |
| operator payments | 28.7 | 27.6 | 54.6 | 30.8 | 12 | 5.7 | 6.9 | 119,940 |
| notifications inbox (25k rows) | 30.5 | 21.7 | 40.5 | 24.9 | 10 | 17.5 | 7.9 | 65,709 |
| bid panel poll (`snapshot`, Livewire) | 2.4 | 3.8 (2.7 with `CACHE_LIMITER_STORE=file`) | 2.9 | 7.3 | 12 (6 with file) | 0.7 | 2.1 | 992 |

* **No page does more work per row.** `QueryBudgetTest` opens 13 pages for a person who is seller, bidder, watcher, winner and
  notification recipient, with 3 lots and again with 30 of each (photos, bids, sales, payments): the query count is identical on every
  page, and a deliberate N+1 planted in the lot card makes it fail. A lot page costs the same with 10 or 100 bids; a poll is at most
  4 queries inside the component (the session, user and limiter queries of the HTTP request come on top).
* **Indexes (EXPLAIN ANALYZE).** Bell unread count for the 25k-notification user 15.8 ms to 0.12 ms (partial index, index-only scan); inbox
  first page 11.1 ms (sort of 25,075 rows) to 1.9 ms (backward index scan); My bids top-bid lookups now index-only.
* **The poll.** A snapshot call is 12 queries / 2.4 ms before the limiter and 6 queries / 2.7 ms with it on the file store; the
  snapshot row itself is one query cached for a second for all viewers. At the default 10 s interval, 1,000 simultaneous viewers
  are about 100 requests a second, roughly 30 percent of one core on this machine.
* **Closing 500 auctions.** `auction:settle-ended` with 550 due (500 synthetic with 3 to 17 bids, 20 percent with an unmet reserve,
  14 percent with no bids; settlement hand-off and notices run inline): **1.3 s total** for 344 sales recorded, 85 sent to reserve
  review, 71 ended unsold; about 2.4 ms per auction.
* **Slowest remaining reads**: the lot list's per-row bid count on a 100,000-bid lot (about 7 ms each time it is listed), `?sort=bids`
  (30 ms of database time), and text search (a scan); see O-4.

## 4. Images and front-end weight

Measured with a 4000 x 3000 JPEG of 4.60 MB (a typical phone photo; the upload limit is 4 MB, so real ones are slightly smaller).

| Copy | Size | Share of original |
|---|---|---|
| original (never sent to a browser any more) | 4.60 MB | 100% |
| `large` 2000 px WebP | 993 KB | 21.1% |
| `medium` 1200 px WebP | 356 KB | 7.6% |
| `thumb` 480 px WebP | 32 KB | 0.7% |

| Scenario | Before | After |
|---|---|---|
| One browse page of 12 lot cards | 55.2 MB of photos | **0.38 MB** (thumbnails) |
| Gallery with 1 main picture and 5 thumbnails | 27.6 MB | **0.51 MB** if the page adopts `medium` and `thumb`; about 6 MB with no change (the default is `large`) |
| Making all three copies at upload | none | 538 ms and 39 MB of memory for a 12-megapixel photo; six photos in one request take about 3 s |

In the real browser, the lot cards loaded `?size=thumb` as `image/webp`, 6 kB for the demo's flat placeholders,
`Cache-Control: private, max-age=2592000`, with an ETag (a repeat request is a 304 with no body).

Front-end (`npm run build`): `app.css` 98.3 kB (18.7 kB gzip), `app.js` 70.3 kB (21.7 kB gzip), `ds.js` 8.7 kB (2.9 kB gzip), error pages
add `errors.css` 21.9 kB (5.3 kB gzip). Fonts are self-hosted: Archivo 90 kB (the only one preloaded, used on every page), Chivo Mono
26 kB (clocks only, not preloaded). HTML is 85 kB for a browse page but 10.7 kB gzipped (confirm the host compresses HTML: not
verified). Livewire adds about 640 bytes of snapshot per lot card for the watch control (7.6 kB of the 85). `legacy.css` is 7.2 kB with
47 `.dot-*` classes of which 19 have no user left (about 1.3 kB of rules: `dot-btn-danger`, `dot-form-grid`, `dot-head`,
`dot-head-actions`, `dot-kpi-grid`, `dot-panel-head` and the twelve `dot-status-*`); delete them with the last users of the others.

## 5. Observability

Checked, and pinned where it could drift: `schedule:list` has all five auction jobs plus the two housekeeping jobs; every overlap lock
is at most 60 minutes; failed settlements are `report()`ed (settle command), logged at **error** (repair sweep, failed hand-off) with
the auction id; the CSP violation line is `info` with only the directive and host. `docs/R42_OPERATIONS.md` has the schedule table,
where to look for each kind of failure, the new-deployment checklist (including `auction:seed-categories --check`), every variable
R-42 introduced with its production value, and the limits.

## 6. For the other workstreams

* **Lot page (ui-auction, `auctions/show.blade.php`)**: use `$auction->imageUrl($photo, 'medium')` for the main picture and `'thumb'` for
  the strip; the "open in a new tab" link can stay `$photo->url()` (the `large` copy). Take `poll_seconds` from the snapshot rather than
  hard-coding; a 429 on `/livewire/update` means the person is over 300 calls a minute. Register the `echo:` listener only when
  `usesEcho()` is true (O-2). Run `RouteSweepTest`, `BidHistoryExposureTest` and `QueryBudgetTest` after merging: they open the page as
  every kind of person and fail on a leaked reserve, e-mail, surname or a per-row query.
* **Already adopted by me**: `ds.lot-card` and `seller/auctions/_lots` (`thumb`), `seller/auctions/form` thumbnails (`thumb`).
* **ui-discover / ui-seller**: `ViewerActivity` lists stop at 120 (SP-14); the dashboard and My bids pages are the heaviest HTML.

## 7. Remaining risk and what was not verified

* **Not run against the production host**: HTTPS and HSTS behaviour, `SESSION_SECURE_COOKIE`, LiteSpeed's handling of odd Host headers,
  gzip of HTML, the cron cadence, the real `.env`. The signed-in smoke with cached routes, config and events passes (30 pages).
* **Paystack was never called**; the gateway was faked, as everywhere in this repo. Webhook signature and amounts were not re-tested.
* **The auction page under test is trunk's, not ui-auction's.** Its new poll and confirm step were not attacked.
* Browser CSP run covered the pages a demo user can reach; the e-mail templates (Dot.Notify renders those) and `/dev/kit` were not.
* Load was measured in-process on one machine, not with concurrent web workers; the 1,000-viewer figure is arithmetic from one poll's cost.
* The bid ledger's delete guard exists on PostgreSQL only; SQLite (development) guards updates only, because a delete trigger that names
  `auctions` breaks SQLite's table rebuilds.
* Photo copies for photos uploaded before this change are made on first request (about 100 to 500 ms once per size per photo, then cached on disk).
* Dependencies: not re-audited (the backend pass cleared `composer audit`); no new package was added.
