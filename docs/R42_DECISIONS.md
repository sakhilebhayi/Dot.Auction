# R-42 backend decisions (Dot.Auction)

Taken on 2026-11-08 by the backend fix agent while the owner was away, on the safest basis. Each one says what was
decided, why, and how to reverse it. Findings (F-xx) refer to `docs/R42_BACKEND_AUDIT.md`. Branch `feat/r42-bf`.

## 1. Account deletion (F-02): block, then anonymise, never cascade

**Decided.** `Features::accountDeletion()` stays on; `App\Actions\Jetstream\DeleteUser` now calls
`App\Services\Auctions\AccountEraser`.

* **Blocked** (a validation message under the password field, listing every reason) while the person:
  * has a listing that is `active` (live or scheduled) or `pending_review` (waiting for their reserve decision);
  * is the leading bidder on an `active` or `pending_review` auction (the seller may still accept their bid);
  * is buyer or seller of a settlement that is `awaiting_payment` or `payment_overdue`;
  * is a party to a settlement that has a payment `unmatched` (money waiting for an operator: the nearest thing this
    app has to a dispute, there is no dispute status);
  * sold, or won, an `ended` auction (last 7 days, `auction.settlement_sweep.lookback_days`) whose settlement is not
    recorded yet (the sweep, item 6, will record it; then they can leave).
* **Otherwise anonymised**: name `Former member`; e-mail `former-member-{id}-{ulid}@deleted.invalid` (`.invalid` can never
  resolve, unique per person); random password; `email_verified_at`, remember token, 2FA secret/recovery
  codes/confirmation, profile photo and current team cleared; API tokens, browser sessions, notifications, watchlist and
  team memberships removed; teams they own are purged (as before); their **drafts** are deleted (nobody has bid on them)
  with their photo files. Bids, published/cancelled/ended auctions and settlements stay on the same user row.
* **Foreign keys** `bids.bidder_id`, `auctions.seller_id`, `auction_settlements.seller_id` and `winner_id` are now
  `RESTRICT` (migration `2026_11_08_000003`, portable, idempotent, `down()` restores CASCADE). A hand-run `DELETE` of such
  a user now fails instead of cascading. `watchlists`, team tables and `auction_settlements.winning_bid_id` stay CASCADE.
* The bid history shows `Former member` (`User::maskedName()`; one line in `bid-panel.blade.php` now uses it).
* A returning person who signs in through InfoDot gets a **new** account (matching is by e-mail, which was replaced).

**Not decided here / open.** A seller with a *paid* sale may leave: payout to sellers is manual and not tracked in this
app, so there is nothing to test for "payout still owed". If the owner wants that blocked, add a `paid_out_at` column and
one more reason in `AccountEraser::obstacles()`. POPIA retention periods for finance rows are the owner's call; the rows
are kept, only the person is anonymised.

**Reverse.** Change `DeleteUser::delete()` back to hard-deleting, and run the migration's `down()`; the cascades return.
Not recommended.

**SQLite note.** Changing a key on SQLite rebuilds the table, which turned the raw partial unique index
`bids_one_winner_per_auction` into a plain `UNIQUE(auction_id)`; the migration puts the partial index back (tested).

## 2. Time zone (F-11): store UTC, show and accept Africa/Johannesburg

**Decided.** `config('app.timezone')` stays `UTC` (storage). `config('auction.display_timezone')`, env
`AUCTION_DISPLAY_TIMEZONE`, default `Africa/Johannesburg`, is what people read and type. An unknown zone name falls back
to Johannesburg. South Africa has no DST, so no ambiguity; the helper is correct for zones that do.

**Helper API** (`App\Services\DisplayTime`, all static):

| Call | Returns |
|---|---|
| `DisplayTime::zone()` / `abbreviation($at = null)` / `label()` | `Africa/Johannesburg` / `SAST` / `SAST (Africa/Johannesburg)` |
| `DisplayTime::toLocal($at)` | a Carbon copy in the display zone (same instant); null stays null |
| `DisplayTime::format($at, $format = 'j M Y, H:i', $withZone = false)` | `"8 Nov 2026, 18:05"`, or `"... 18:05 SAST"`; `""` for null |
| `DisplayTime::inputValue($at)` | `"2026-11-08T18:05"`, the value of an `<input type="datetime-local">` |
| `DisplayTime::parseInput($value)` | Carbon **in UTC** from a datetime-local string read as Johannesburg time, or `null` (not a date, 31 Feb) |
| `DisplayTime::iso($at)` | ISO 8601 with the display offset, `2026-11-08T18:05:00+02:00` |

Also: Carbon macros `$at->local()` and `$at->localFormat($format = null, $withZone = false)`; `Auction::localTime($at,
$format = null, $withZone = false)`; Blade `@localtime($at, 'j M Y, H:i', true)` (prints escaped text). Rule for UI work:
**never `->format()` a stored time in a view**; `Tests\Feature\R42\DisplayTimezoneTest::test_no_view_formats_a_stored_time_in_utc`
fails on `->starts_at|ends_at|paid_at|payment_due_at|created_at|...->format(` in `resources/views`.

**Converted.** Seller listing form input (`SaveListingRequest::parseDate`, now via `DisplayTime::parseInput`) and the values
put back into the form (`ListingController`, `DisplayTime::inputValue`); the publish flash message; the bid panel
"closing time extended to ..." message (now `08:07:00 SAST`); every view that printed a stored time (list below);
Dot.Notify event data `ends_at` (outbid, ending soon) now carries the `+02:00` offset (same instant; Notify's catalogue
keys are unchanged). Database-notification texts use relative times (`diffForHumans`) or none, so they are zone-free.
`config('app.timezone')` is no longer printed anywhere as a label.

**Views touched (minimum, helper calls only):** `dashboard`, `auctions/index`, `auctions/_settlement-panel`,
`auctions/show` (also Buy Now amount via `Auction::money`), `livewire/auctions/bid-panel` (also bidder name via
`maskedName()`), `livewire/auctions/reserve-decision-panel`, `operator/settlement-payments`, `seller/auctions/_table`,
`seller/auctions/form`, `settlements/index`, `settlements/show`.

**Reverse.** Set `AUCTION_DISPLAY_TIMEZONE=UTC`: everything displays and parses as before.

## 3. Reserve not met (F-09): seller is told, once; no rules invented

**Decided and built.** Listener `NotifySellerOfReserveNotMet` (queued) on `ReserveNotMet` sends
`ReserveNotMetNotification` to the seller: an in-app (database) notice, `url` = the dashboard, where the "Awaiting Your
Decision" panel is. Idempotent: claimed with a conditional `UPDATE reserve_not_met_proposals SET seller_notified_at = now()
WHERE seller_notified_at IS NULL AND status = 'pending'` (new nullable column, migration `2026_11_08_000002`); the claim is
released if sending throws so the queue retry can send. The e-mail copy (Dot.Notify event `auction.reserve_not_met`, keys
`auction_title, current_price, currency, url`) exists but is **off** until `NOTIFY_RESERVE_NOT_MET_EVENT=true`, because
Notify's catalogue must have the event first or Notify answers 422 and the log fills with refusals.

`ReserveNotMetProposal::awaiting_since` (accessor) is `created_at` while the proposal is pending and `null` after the
decision; screens can show "waiting since ...".

**Needs owner rules (not built, nothing invented):** a decision **deadline**; **what happens at the deadline** (auto-reject,
auto-accept, relist, remind); **notices to bidders** (the high bidder is told nothing today when the seller rejects);
whether the seller may counter-offer. Until then a `pending_review` auction waits indefinitely, as before.

**Reverse.** Delete the listener and notification files (the column can stay).

## 4. Dead code (F-17, F-18)

* `AutoBidEngine` deleted (no references; unlocked, float based). `bids.is_auto_bid` kept. README, wiki and
  `docs/deployment.md` lines fixed. Proxy bidding, if wanted, needs its own design inside `BidPlacer`'s lock.
* `AuctionItem` **left**: `Auction::items()` and `auctions/show.blade.php` still read it (nothing creates it). Remove them
  together with the table when the UI work confirms no use. `auctions.image_path` is also left (a column).

## 5. Dependencies (F-12)

`composer update` of `symfony/{http-foundation,http-kernel,routing,mime,yaml}` with dependencies: 8.1.9 to 8.1.11
(mime 8.1.10), plus their contracts/error-handler/var-dumper/polyfill-mbstring patch bumps. `composer audit`: clean
(was 8 advisories in 5 packages). `npm audit fix` (no `--force`): vite 8, rolldown, concurrently, shell-quote,
browserslist advisories fixed, `npm run build` verified. **Left:** 2 moderate advisories in `@tailwindcss/typography`
(postcss-selector-parser), fixable only by a breaking change. Both suites green after.
**Reverse:** `git revert` the commit (restores both lock files).

## 6. Settlement sweep (F-06)

`php artisan auction:repair-unsettled`, hourly, `withoutOverlapping(15)`, `onOneServer`. Finds `ended` auctions with a
winning bid, no settlement, no *rejected* reserve proposal, that closed more than `grace_minutes` (15) ago and at most
`lookback_days` (7) ago, and calls `AuctionSettler::repairMissingSettlement()`: under the auction row lock it records the
settlement through `RecordSettlementForPayment` (idempotent, unique `auction_id`), so a repeat or overlap creates nothing
and tells nobody twice. Each repair is logged at **error** level
(`Repaired an ended auction that had a winner but no settlement.`, `auction_id`); a failure is reported and the exit code is
non-zero. The winner is not re-notified (their notice went out when the auction ended). Window values are safety limits
(env `AUCTION_SWEEP_LOOKBACK_DAYS`, `AUCTION_SWEEP_GRACE_MINUTES`), not auction rules. **Reverse:** remove the schedule line.

## 7. Smaller fixes

* **F-19** `trustHosts` from `security.trusted_hosts` (env `TRUSTED_HOSTS`, comma list); empty means the `APP_URL` host
  only. Not enforced while `APP_URL` is the `localhost` default, nor in `local`/tests (Laravel's own rule), and **`/up` and
  `/up/*` are exempt** so IP or host-less health probes keep working; the deploy smoke check uses the real domain.
  Verified under cached config (`signed-in-smoke.sh`): `evil` host 400, `/up` with it 200. Add the `www` form to
  `TRUSTED_HOSTS` if it is ever served.
* **F-24** Category counts exclude drafts; a draft id answers **404** (not 403) to everyone but its seller, for the page and
  its photos (`AuctionPolicy::view` uses `Response::denyAsNotFound()`).
* **F-25** `Auction::money()` / `auction.currency_symbol` everywhere in PHP, notifications and the Buy Now tile; a test
  fails on a hard-coded `R` in `app/` or `resources/views`.
* **F-28** `$fillable`: `Auction` keeps only the listing-form columns; `Bid`, `AuctionSettlement`, `SettlementPayment`
  have none. System columns (seller, status, prices, `snipe_extensions`, settlement and payment fields) are written with
  `forceFill()`/`forceCreate()` in the services that own them. Tests create fixtures with `forceCreate`.
* **F-26** `config('auction.realtime.enabled')` (env `AUCTION_REALTIME_ENABLED`, default true). When false,
  `/up/realtime` answers **200** `{"status":"degraded","mode":"polling"}`; when true behaviour is unchanged (503 if Reverb
  is down). Set it to `false` in the production `.env`.
* **F-27** `BidPanel::buyNow()` is idempotent: a second click or replay leaves the success message; a second tab of the
  same buyer after a completed Buy Now reads the database and shows the success state, not "no longer active". Someone
  else's purchase is still an error.
* **F-29** Invalid bid text sets `$error` and the validation bag (`bidAmount`) to "Enter your bid as a number, for
  example 250 or 250.50." (or "Enter the amount you want to bid."), so the existing `$error` line shows it today and
  `@error('bidAmount')` can show it later.

## 8. Live bidding without Reverb (F-04, backend half): `BidPanel::snapshot()`

`BidPanel::snapshot()` is `#[Renderless]` (no DOM diff) and returns the array below; call it from the page with
`$wire.snapshot()` on a timer (e.g. `setInterval`, or `wire:poll` on a hidden element that calls it). Source:
`App\Services\Auctions\AuctionSnapshot`. One SELECT (auction row + bid count + leader id, two correlated sub-selects),
cached **1 second** per auction (shared by all viewers, cache store as configured); cleared after every bid, Buy Now and
settlement commit. Viewer- and clock-dependent fields are computed per call from the cached row.

| Field | Meaning |
|---|---|
| `auction_id` | int |
| `status` | `scheduled`, `active`, `ended` (also as soon as `ends_at` has passed, before settlement runs), `pending_review`, `cancelled`, `draft`, or `missing` |
| `current_price`, `current_price_cents` | `"300.00"` string and int cents |
| `minimum_bid` | `"310.00"`, the least the next bid may be |
| `bid_count` | int |
| `starts_at`, `ends_at` | ISO 8601 UTC with `Z` |
| `seconds_remaining` | int by the **server** clock, `null` unless `active`, never negative |
| `leading_bidder_is_me` | bool (false for guests) |
| `server_now`, `server_now_ms` | the server clock at this call (ISO / epoch ms) |
| `poll_seconds` | suggested interval (`auction.realtime.poll_seconds`, env `AUCTION_POLL_SECONDS`, default 10) |
| `realtime` | true when Reverb pushes updates (`auction.realtime.enabled`) |

Client countdown, immune to a wrong device clock: on every snapshot store `offset = server_now_ms - Date.now()` and tick
`remaining = Date.parse(ends_at) - (Date.now() + offset)`; or count down `seconds_remaining` from the moment the snapshot
arrived. At zero show "Ended" locally, then take the next snapshot. The reserve price is never in the snapshot. The view
was **not** edited; the auction-detail UI work wires the poll and the countdown.

## 9. Signed-in smoke with production caches

`sh tests/Support/signed-in-smoke.sh` builds a throwaway SQLite database, runs `route:cache`, `config:cache`,
`event:cache`, opens 23 authenticated pages as the right person (`tests/Support/SignedInSmoke.php`), expects 200, checks
host pinning, fails on any authenticated route not listed (or explained in `SKIPPED`), then removes the caches.
`SignedInSmokeTest` runs the same check inside the normal suite. It never touches the real `.env` database.

## Environment variables added

`AUCTION_DISPLAY_TIMEZONE`, `AUCTION_REALTIME_ENABLED`, `AUCTION_POLL_SECONDS`, `AUCTION_SWEEP_LOOKBACK_DAYS`,
`AUCTION_SWEEP_GRACE_MINUTES`, `TRUSTED_HOSTS`, `NOTIFY_RESERVE_NOT_MET_EVENT`. Migrations added:
`2026_11_08_000002` (nullable column), `2026_11_08_000003` (foreign keys RESTRICT).

## UI: seller area, payments and payment operations (ui-seller, 2026-11-08)

Built on the Rostrum kit (`DESIGN.md`); no auction rule was added or changed. Skills used: `impeccable` (operate mode, craft floor), `hallmark` (floor, locked DESIGN.md), `design-taste-frontend` (app screens), `emil-design-eng` (press, dialog and menu motion), `ui-ux-pro-max` (checklist only), `awwwards` (quality bar).

### Taken

| # | Decision | Why |
|---|---|---|
| U-1 | One word for people: **Payments** (nav, page, titles, copy); **Payment operations** for the operator page. The words "settlement" and "settlement payments" no longer appear on a screen. Routes and class names are unchanged. | Brief item 5. |
| U-2 | `/seller/auctions` filters by state through `?state=` (pending, live, ending, scheduled, draft, ended, cancelled). Counts and rows come from one definition (`App\Support\SellerLots`), so a tab can never disagree with its count. Tabs with nothing in them are not offered. "Closing soon" is a subset of "Live". | Brief item 1. |
| U-3 | A lot that waits for the seller's reserve decision carries the real `reserve-decision-panel` (Livewire) in a band under its row. The list does not duplicate the panel's logic. After a decision the panel shows its own result; the row's status updates on the next load. | Brief item 1. ui-auction owns the panel's look. |
| U-4 | An `active` lot whose close time has passed but which the job has not settled yet shows "Closed, being settled", not "Not sold". `Display::lotState()` still says `unsold` for it (shell file, left alone). | Telling a seller with bids that the lot did not sell for up to five minutes was wrong. |
| U-5 | Closing time on a new lot starts as 7 days out at 18:00 display time, and "Close it after 1, 3, 7, 14 days" fills the field (only durations within `listing.max_duration_days`). The bid-step suggestion is about 5% of the starting price rounded to 1, 2 or 5 times a power of ten, and appears only while the increment is empty. All of it is a suggestion the server validates again. | "Sensible defaults", brief item 2. Not a rule: the seller can type anything the existing validation allows. |
| U-6 | The photo drop zone sends the existing upload request with XMLHttpRequest (for progress) and asks for JSON. `ImageController::store` and `UploadAuctionImagesRequest` answer JSON when asked, with the same rules and messages. Without JavaScript the plain form post works as before. | Progress and error feedback. Needed `failedValidation` override because the app renders JSON errors only for `api/*`. |
| U-7 | "Pay by" / "Was due" is shown only while payment is actually switched on. "Pay now" appears on the payments list (posts to the existing pay route) and on the sale page. The mode badge (Test mode / Live mode) is the shell's; the pages no longer print their own. | Brief item 3. |
| U-8 | The payout note ("Payout is not available yet ... settlement is manual") is printed once, on the seller's sale page, in its own "Your payout" section, and once as a single line under the seller's rows on the list. | Brief item 3. |
| U-9 | The Paystack return is titled by state through a new `payment_state` session flash (paid, pending, failed, held, unconfirmed, gateway). The messages themselves are unchanged. | Brief item 3. |
| U-10 | The sale page shows a history built from `SettlementEvent` types in plain sentences ("Payment opened for the buyer", "You cancelled the sale"). The audit `note` text is never printed. | Internal notes carry ISO timestamps and references. |
| U-11 | Operator filters (`?status=`) with counts; `?show=attention` keeps working. The table is stacked cards below 40rem and a focusable, labelled scroll region from 40rem. | Brief item 4. |

### Left as is, for whoever merges

* `seller/auctions/_table.blade.php` (the old table) is still included by the dashboard and still uses `.dot-*` classes and inline styles. It belongs to ui-discover's rewrite of the dashboard; delete it, and the `.dot-table`, `.table-scroll`, `.dot-actions`, `.dot-status*` rules in `legacy.css`, when the dashboard stops including it. This branch removed only the rules nothing uses any more.
* `settlements/_mode.blade.php` is no longer included by my pages but `auctions/_settlement-panel.blade.php` (ui-auction) still includes it; remove both together with `.dot-mode`.
* Shell bug, not fixed (shell file): `layouts/app.blade.php` builds the h1 with `strip_tags((string) $header)` from the header slot, so a header slot that contains an escaped `&` or `<` (a lot title) prints `&amp;` in the heading. The sale page therefore passes its title through the `title` prop and no header slot. `html_entity_decode()` around the strip would fix it for everyone.

### Needs an owner rule (nothing was invented)

1. **Photo order and the cover.** The first photo added is the cover and there is no way to reorder. A "make cover" or drag-to-reorder control needs a rule about positions (the backend has `position` but no endpoint).
2. **Photos on the first save.** A new lot is saved first and photos come on the next screen. Uploading with the first save would need the draft and the photos to be created together.
3. **Payout tracking.** The seller's page can only say that payout is manual. A "paid out on ..." state (and who records it) is a product decision; so is whether a seller may see the buyer's name on a paid sale.
4. **What cancelling an overdue sale promises.** The page says it does not relist and does not refund anyone. Whether a cancel should offer to relist the lot is a rule.
5. **Closing time default and bid step suggestion (U-5).** Fine as suggestions; if the owner wants category-specific defaults they need a table of them.
6. **Reserve decision deadline and what the bidder is told** (F-09, already above): the list now shows "waiting since" nowhere because there is no deadline to count against.
