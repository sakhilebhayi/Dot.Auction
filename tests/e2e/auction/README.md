# Dot.Auction: real-browser end-to-end specs (buyer side)

Self-contained: its own `package.json` (`playwright-core` to drive Chrome, `axe-core` for accessibility). It never touches your
`.env` database. Each spec boots its own copy of the app on a free port, against a fresh SQLite file, migrated and filled with the
demo world (`php artisan auction:demo-seed --fresh`, see `docs/R42_DEMO.md`).

```bash
cd tests/e2e/auction
npm install                       # once: playwright-core + axe-core, nothing else
(cd ../../.. && npm run build)    # once: the Vite assets the pages load (public/build is not committed)
node run.mjs                      # every spec, one after another (about 15 minutes)
node run.mjs b4 b7                # only the specs whose file names start with b4 or b7
```

Needs: PHP 8.5 with SQLite, the `sqlite3` command line tool, and Google Chrome (found at
`/Applications/Google Chrome.app`, or set `E2E_CHROME=/path/to/chrome`). Chrome is started headless; no browser is downloaded.

Output goes to `out/` (git-ignored): `out/results.json` (all specs), `out/results/<id>.json` (every check with its detail),
`out/shots/<id>/*.png` (1440 and 390 wide, light and dark), `out/logs/<id>.server.log`, `out/db/<id>.sqlite` (the database of the last run).

## What each spec is

| Spec | Subject |
|---|---|
| b1 | First login for five kinds of buyer: Home tells the truth, "Needs you" links work, no dead links |
| b2 | Browse: search, categories, every state tab, every sort, pagination, empty states, card labels and cues |
| b3 | Every lot page for every viewer (22 lots x 7 people), judged against the database |
| b4 | Bidding: guard rails, confirm dialog, races, polling, anti-snipe, device clock skew, Buy Now, watch, rate limit |
| b5 | My bids, Watching (with and without JavaScript), Won: own rows only |
| b6 | The bell and the inbox, events created by the real commands, destinations, hostile addresses |
| b7 | Paying as the winner against a fake Paystack: success, failure, abandon, pending, gateway down, duplicate callback and webhook |
| b8 | Phone (390 and 360), keyboard-only, reduced motion, light and dark on every page, zoom |
| b9 | What a buyer must not reach, expired session, lost connection, server failure, 429, the error pages |

## How a spec works

* A "person" is a browser context of its own, signed in through the local-only `/dev/login/{email}` route. Two people means two
  contexts, so "another person bids" is a real second browser, not a mock.
* Every check is recorded (pass or fail, with detail). The console and the network are watched: any console error or warning,
  page error, failed request or 4xx/5xx response fails the spec unless the spec said it expected it (`p.tolerate(...)`).
* Time is moved in the database (`update auctions set ends_at ...`) and then the real command runs
  (`auction:settle-ended`, `auction:notify-ending-soon`, `auction:mark-overdue-payments`). The browser's clock is not faked, except where
  a spec deliberately skews the device clock to prove the countdown follows the server.
* Outcomes are asserted in the database as well as on screen.

## The fake Paystack (testing only)

`Http::fake()` lives inside one PHP process and cannot reach a browser run, so the runner starts the app with
`AUCTION_E2E_FAKE_PAYSTACK=1` and `PAYSTACK_SECRET_KEY=sk_test_e2e_not_a_real_key`. `AppServiceProvider` then binds
`App\Services\Paystack\E2eFakePaystackClient` in place of the real client, and `routes/web.php` registers a fake checkout page and a
mode switch under `/dev/fake-paystack...`. Both exist only when the environment is `local` or `testing` AND the flag is on
(`tests/Feature/R42/E2eFakePaystackGuardTest.php` pins that). No real key and no network call is involved.
