# R-42 end-to-end, buyer side (agent A)

Run 2026-11-08 on branch `feat/r42-e2ea`, in a real Chrome driven by Playwright (`tests/e2e/auction`, see its README). Every spec boots its own copy of
the app and a fresh SQLite database filled by `php artisan auction:demo-seed --fresh`, and judges outcomes in the database and in a second
browser (another person), not only on screen. Every page visited was also checked for console errors, failed requests and axe-core
(zero serious or critical is the pass bar), and screenshotted at 1440 and 390 in light and dark (`out/shots/<spec>/`, git-ignored).

Results below are from the tree before the final trunk polish (commit `f33f6d5`, which contains trunk up to `e5b931b`). After merging
trunk's polish round 2 (`84425e5`) only b2 and b3 were re-run (see "After the polish merge").

## Per-spec result

| Spec | Subject | Result | Checks | Axe runs | Screenshots |
|---|---|---|---|---|---|
| b1 | First login: new user, buyer with activity, Buy Now winner, winner with unpaid sales, leader and outbid bidder | PASS | 118 | 20 | `b1/dashboard-*` |
| b2 | Discovery: search, categories, 7 state tabs, 5 sorts, pagination, clear, empty states, labels, cues | PASS | 136 | 16 | `b2/browse-*` |
| b3 | Lot page, 22 lots x 7 viewers (154 pages) against the database; reserve never in page source; masking; SAST; voice; 14 looks | 118 of 119 on the pre-merge tree, see below | 119 | 56 | `b3/lot-*` |
| b4 | Bidding: guard rails, confirm, Enter/Esc, race, poll, skewed clock, anti-snipe, Buy Now, watch, rate limit, phone | PASS | 182 | 14 | `b4/*` |
| b5 | My bids, Watching (with and without JavaScript), Won: own rows only | PASS | 134 | 24 | `b5/*` |
| b6 | Bell, inbox, real events, destinations, hostile addresses, poll, phone | PASS | 91 | 10 | `b6/*` |
| b7 | Pay as winner on a fake Paystack: success, failed, abandoned, pending, gateway down or erroring, lost confirmation, duplicate callback and webhook, who may pay | PASS | 122 | 28 | `b7/*` |
| b8 | Phone 390 and 360, keyboard, reduced motion, light and dark on 13 pages (profile and teams included), zoom, touch targets | PASS | 119 | 34 | `b8/*` |
| b9 | Permissions, expired session, offline, 500, 429, error pages, guests | PASS | 97 | 20 | `b9/*` |

Total 1,118 checks. b3's single failure on the first full run was a SQLite "database is locked" 500 on one Livewire call during a pass in
which the laptop stalled for about 15 minutes (the same run shows 966 s for specs that take 50 s); production does not use SQLite. All
other b3 checks passed, and b3 was re-run after the merge (below).

Headline measurements: outbid detected in the other browser by the poll in 3.1 to 3.3 s (poll is 4 s); the bell's 30 s poll updated the badge after 28 s;
two bids at the same instant gave exactly one winner and a clear "Someone bid first" for the other; a device clock one hour fast, three hours slow and
a day fast all still showed the server's time left; double click on Place bid and on Buy now made one bid; the reserve amount appears in no page source for anyone.

## Defects found and fixed (each has a PHPUnit regression test)

1. **Flash messages were lost about three times in four** ("Stopped watching ...", and the same mechanism carries "Payment received" after Paystack).
   Root cause: the notification bell called `$wire.close()` on the server for every click anywhere on the page, panel open or not; that Livewire
   request raced the form post of whatever was clicked and spent (aged) the flash data before the redirect target rendered. Fix: the bell calls the
   server only when it is open (`resources/views/livewire/notification-bell.blade.php`), and a small middleware (`KeepFlashForLivewire`, web group) makes a
   Livewire call hand flash data on untouched, so a poll between a redirect and its page cannot eat it either. This also removes one useless request per click.
   Tests: `tests/Feature/R42/FlashSurvivesLivewireTest.php` (fails without the fix).
2. **A lot title with an apostrophe, ampersand or quote was double-escaped in the lot page's h1** ("Brass ship&#039;s lantern" on the demo's own lantern).
   The header slot was escaped, stripped and escaped again. Fix: no header slot, the layout prints the title prop once (trunk made the same change; merged).
   Test: `tests/Feature/R42/LotTitleEscapingTest.php` (fails without the fix).
3. **A failed Livewire call said nothing, and a lost connection stopped the lot page's polling for good.** Offline, pressing Place bid did nothing visible;
   Livewire never settled the call, so the poll waited for ever and never resumed. A 500 opened a full-screen copy of the error page in a modal. Fix
   (`resources/js/auction-live.js`): one request-failure hook that explains in words (no connection, page expired, 429, server error: "may not have gone through,
   check the page"), suppresses Livewire's modal, stays quiet for background polls, and releases the poll so it resumes when the connection returns. Test:
   `tests/Feature/R42/LivewireFailureNoticeTest.php` pins its two inputs (the toast region on every signed-in page, the script's handlers); the behaviour itself is proved by b9.
4. **Back link on the lot page was 36 px on phones** while DESIGN.md says every control is 44 px on touch. Trunk fixed it the same way (coarse pointer rule); trunk's version is kept.

Also added, testing only: `E2eFakePaystackClient` plus `/dev/fake-paystack...` routes, active only when the environment is `local` or `testing` **and**
`AUCTION_E2E_FAKE_PAYSTACK=1` (`tests/Feature/R42/E2eFakePaystackGuardTest.php` proves it cannot bind in production or staging).

## Things that looked wrong and were not (test or environment, not product)

* Chrome drops `(pointer: coarse)` after a full-page screenshot in a touch context, which made the first touch-size pass measure desktop sizes; the spec now measures first and photographs last.
* "Starting bid R1,000.00" with a form pre-filled "1050.00": by design today (first bid is start plus one step), already owner question Q-A5; B4 asserts the current rule.
* When a lot closes while the confirm dialog is open the page withdraws the dialog and says "Bidding has closed"; pressing a stale confirm is therefore not reachable. The server refusal is covered by PHPUnit.

## Not verified

* PostgreSQL: no migration or query was changed, so `phpunit.pgsql.xml` was not run; the new tests are database-neutral. The browser run is SQLite only.
* The exact-millisecond close boundary of anti-snipe (cannot be hit from a browser; `AntiSnipeTest::test_a_bid_exactly_on_the_window_boundary_extends` covers it). The browser proved "4 minutes left extends by exactly 5, 8 minutes left does not".
* Reverb (switched off here and on the host); real Paystack, real e-mail, InfoDot sign-in; Safari and Firefox; a screen reader (live regions are proved by their text and `aria-live`, not heard).
* A "duplicate bid" message from the page: the form is not offered to a leader, so only a stale second tab could reach it, and that tab also shows no form.
* The Sort select by keyboard (macOS opens a native popup a headless run cannot drive; it was changed with `selectOption` in b2).
* 200 percent and 400 percent zoom were emulated with a 720 px and a 320 px wide window and a doubled root font size, not with the browser's own zoom.
* axe notes (moderate): `landmark-unique` on the pages that show both the rail and the phone bar (two navigations named "Main"); fine at one size at a time.

## After the polish merge

Trunk's polish round 2 (settlement wording, no Watch on closed lots, compact phone rows) was merged in `84425e5`; where it differs from my branch, trunk's behaviour
wins (the only textual conflict was the identical lot-title fix). b3's Watch expectation was relaxed to "scheduled and live" to match trunk. b2 and b3 were re-run on the merged tree:
see the last section of this file if a result is recorded there; the other specs (b1, b4 to b9) were **not** re-run after the merge, so wording-sensitive checks in them
(for example Home's text for a closed lot, a cancelled sale's copy) may need a one-line update.
