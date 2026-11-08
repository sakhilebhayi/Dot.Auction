# Dot.Auction browser end-to-end suite B (seller, operator, account, platform)

Real Chrome (through `playwright-core`), a real `php` server, a real SQLite file per spec, the real scheduler commands. Nothing is mocked inside the app except two testing-only stand-ins for services that live outside it (see below).

```bash
cd tests/e2e/auction-b
npm install                 # playwright-core (the browser driver) and axe-core (accessibility rules); no browser download
cd ../../.. && npm run build && cd tests/e2e/auction-b   # the specs need public/build
node run.mjs                # every spec          (about 4 minutes)
node run.mjs s2 s5          # only these (prefix match on the file name)
E2E_CHROME="/path/to/chrome" node run.mjs   # default: /Applications/Google Chrome.app
```

Results: `out/<spec>.json` (every check with its detail, notes, axe summary), `out/summary.json`, screenshots in `out/shots/<spec>/` (1440 and 390, light and dark where it matters), the server log of each spec in `out/<spec>.server.log`. `out/` is gitignored. The runner exits non-zero if any check fails.

## How a spec runs

1. `lib/server.mjs` picks a free port, creates `out/<spec>.sqlite`, runs `php artisan migrate`, then `auction:demo-seed --fresh` (docs/R42_DEMO.md) and starts `php -S 127.0.0.1:PORT` with Laravel's own `server.php` router. (`php artisan serve` is the same command but it strips every environment variable except a short list, which would lose the per-spec database; the repo's `.env` is never touched or read for these values.)
2. The spec drives Chrome (`lib/harness.mjs`). Every context watches console errors, page errors, failed requests and any HTTP status of 400 or more; the last check of every spec fails if one happened that the spec did not announce with `t.expectHttp(...)`.
3. Time is moved by writing `ends_at`, `starts_at` or `payment_due_at` in the database (`app.moveLot`, `app.at(minutes)`), then running the real commands (`auction:settle-ended`, `auction:notify-ending-soon`, `auction:mark-overdue-payments`, `auction:repair-unsettled`). Outcomes are asserted in the database and in a second browser context (the other person's view).
4. Every axe run fails on `serious` or `critical` violations; minor ones are listed in `out/<spec>.json`.

`PHP_CLI_SERVER_WORKERS=1`: SQLite refuses a second writer that starts a transaction as a reader, and one worker keeps the runs deterministic.

## Specs

| File | What it proves |
|---|---|
| `s1-seller-lot.mjs` | create, validation summary and jump links, SAST stored as UTC, photo rules (type, size, count, disguised files, decompression bomb), draft, publish, edit, locked terms after a bid, cancel, delete, scheduled lots, list tabs, phone layout |
| `s2-closing.mjs` | settlement exactly once, notices, anti-snipe, no-bid lots, reserve not met, the seller's decision (accept and reject), state on every page, a failed settle logged at error level, the repair sweep |
| `s3-settlements.mjs` | sale pages in every state for seller, winner and stranger, overdue by the real job, seller cancel with confirm, operator payments page, 403s, phone cards |
| `s4-account.mjs` | profile, password, browser sessions, two-factor (real TOTP), recovery codes, account deletion (blocked, then anonymised), teams, API tokens off, theme and contrast |
| `s5-platform.mjs` | public pages and honesty, login, the InfoDot hand-off, live polling after it, Host pinning (a second server in production mode), health, security headers, CSP reports, every error page |
| `s6-scheduler.mjs` | the schedule and its overlap guards, ending-soon notices once, an empty platform with no categories |
| `s7-sweep.mjs` | every page the seller, winner and operator reach at 390 and 360, keyboard walk, focus rings, dialogs, reduced motion, zoom and tablet widths |

## Testing-only stand-ins

* `AUCTION_E2E_FAKE_INFODOT=1` binds `App\Services\InfoDot\E2eFakeIdentityClient` in place of the InfoDot redeem call, only when the app runs in `local` or `testing`. Token `e2e:{email}[:{name}]` redeems to that verified person, `e2e-down` behaves like an unreachable InfoDot. `harness.ecosystemSignIn()` posts the token from a page served on `http://infodot.test` (the app's origin check looks at the `Origin` host; Chrome sends `Origin: null` on an https to http form post, which is why the stand-in page is http).
* Payments: no spec presses Pay now, so no Paystack stand-in was needed. `PAYSTACK_SECRET_KEY` is a fake test key and no spec reaches the network. If a spec ever needs the gateway, add the testing-only fake behind `AUCTION_E2E_FAKE_PAYSTACK=1` (same flag as the buyer suite A).
