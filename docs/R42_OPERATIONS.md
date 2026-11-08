# R-42 operations: what runs when, where failures show, and the settings R-42 added

Written 2026-11-09 by the security and performance workstream. The host-side steps (cron lines, the shared crontab, deploy
pipeline) are in `docs/deployment.md`; this page is the short operator's view of the same machine and of what R-42 added to it.

## 1. What runs on its own

The host has two cron lines (installed by the deploy, marked `# dot-auction`): the scheduler once a minute and a queue
worker once a minute. Everything below is started by the first; the second runs queued jobs.

| Job (`php artisan schedule:list`) | When (UTC) | What it does | If it stops |
|---|---|---|---|
| `auction:settle-ended` | every 5 min | Closes auctions whose time has passed: picks the winner, applies the reserve rule, queues the sale record | Ended auctions stay "closing" and nobody is told |
| `auction:notify-ending-soon` | every 5 min | One "closing soon" notice per watcher per auction | Watchers get no notice (nothing else is lost) |
| `auction:mark-overdue-payments` | every 15 min | Starts the payment clock on old sales; marks unpaid sales past the deadline overdue | Overdue sales stay "awaiting payment" |
| `auction:repair-unsettled` | hourly | Re-creates a missing sale record for an ended auction that has a winner (a lost queue hand-off). Logs every repair at **error** level | A lost hand-off is never repaired |
| `auction:publish-metrics` | daily 00:15 | One aggregate snapshot to Dot.Analytics (off until configured) | Dot.Analytics shows no new day |
| `queue:prune-failed --hours=336` | daily | Drops failed jobs older than two weeks (R-42) | `failed_jobs` only grows |
| `auth:clear-resets` | daily | Clears expired password-reset tokens (R-42) | The table only grows |

Every one is `withoutOverlapping` with a lock of at most 60 minutes (a killed run cannot block a day of settlements) and
`onOneServer`. Deploying puts the app in maintenance mode, which also pauses the scheduler and the worker: do not deploy in
an auction's closing window.

**Queued work** (`QUEUE_CONNECTION=database`, one worker a minute, `--max-time=50`): `RecordSettlementForPayment` (the sale
record and the seller's "sold" notice), `NotifySellerOfReserveNotMet`, `SendNotifyEvent` (e-mail through Dot.Notify) and
`SendAnalyticsSnapshot`. A job waits up to a minute for a worker. Bids themselves, the outbid notice and the bid panel poll do
not wait for the queue.

## 2. Seeing failures

| Question | Where |
|---|---|
| Did the scheduler run? | `storage/logs/cron-scheduler.log` (overwritten each minute: the last run only) and `php artisan schedule:list` |
| Did the worker run? | `storage/logs/cron-queue.log` |
| What failed? | `storage/logs/laravel*.log` (set `LOG_STACK=daily` so it rotates). Settlement failures: `report($e)` from `auction:settle-ended`; **error** level for `Repaired an ended auction that had a winner but no settlement`, `Recording the sale of an ended auction failed after every retry`, and `A seller opened the listing form but there are no auction categories` |
| Which jobs gave up? | `php artisan queue:failed`; retry with `php artisan queue:retry all` |
| Is anything wrong with the sales data? | `php artisan auction:repair-unsettled` (safe to run by hand; repeats create nothing) |
| Who is hitting a limit? | HTTP 429 in the web server log. Limits: sign-in 5 a minute per address and e-mail; password-reset mail 5 a minute per address and e-mail; text search 60 a minute per person; listing writes 60 a minute; photo uploads 20 a minute; Livewire calls 300 a minute per person; bids and Buy Now 20 a minute per person; Pay 12, Pay callback 30 and Cancel 12 a minute per person; CSP reports 60 and the Paystack webhook 120 a minute per address |
| Is the content-security policy blocking something real? | `grep csp.violation storage/logs/laravel*.log` (one line per directive and blocked host per day). Zero is expected |
| Is real-time off as intended? | `GET /up/realtime` answers 200 `{"status":"degraded","mode":"polling"}` when `AUCTION_REALTIME_ENABLED=false` |
| Is the database reachable? | `GET /up` (a failing query makes it 500) |

## 3. New deployment checklist

1. `php artisan migrate --force` (the R-42 migrations: foreign keys `RESTRICT`, indexes, the bid ledger guard, the reserve notice column).
2. **Categories.** A seller cannot save a lot without one. `php artisan auction:seed-categories --check` exits 1 when there are none; `php artisan auction:seed-categories` adds 25 South African marketplace categories. It is idempotent (matched by slug), never renames or removes anything, and is never run automatically. `--dry-run` shows what it would add.
3. `php artisan auction:operator someone@example.com` for each payment operator.
4. Photo copies: nothing to run. New uploads get their thumbnail, medium and large copies at upload; photos uploaded earlier get theirs the first time they are asked for.
5. Set the environment values below.

## 4. Settings R-42 added (and the production value to use)

| Variable | Production value | What it does |
|---|---|---|
| `AUCTION_REALTIME_ENABLED` | `false` (no Reverb on the host) | `true` makes `/up/realtime` answer 503 when Reverb is down; `false` reports polling mode with 200 and makes the bell and bid panel poll |
| `AUCTION_POLL_SECONDS` | `10` | How often the bid panel asks for a snapshot. Each poll is ~3 ms of server time and ~1 kB; 1,000 viewers is about 100 polls a second |
| `AUCTION_DISPLAY_TIMEZONE` | `Africa/Johannesburg` | The zone people read and type times in. Storage stays UTC; never change `APP_TIMEZONE` |
| `TRUSTED_HOSTS` | empty (APP_URL host only), or `auction.infodot.co.za,www.auction.infodot.co.za` | A request for any other Host gets 400. `/up` is exempt |
| `SESSION_SECURE_COOKIE` | unset (follows `APP_URL`: `https://` means Secure) | Set `true` explicitly if `APP_URL` is still `http://` while the site is served over HTTPS (fix `APP_URL` instead) |
| `SECURITY_CSP_REPORT_ONLY` | `false` (enforced; the default) | `true` makes the same policy report-only without a deploy, for example while a new page is being built |
| `SECURITY_CSP_CONNECT_SRC` | empty | Extra origins the page may open connections to (space separated). Nothing outside the site is allowed by default |
| `SECURITY_HSTS_SECONDS` | `15552000` (180 days) | HSTS on HTTPS responses; no `includeSubDomains`, no preload, because sibling platforms share the domain |
| `CACHE_LIMITER_STORE` | `file` | Keeps rate-limit counters in files instead of the database cache: half the queries per Livewire call (12 down to 6) |
| `LOG_STACK` | `daily` | Rotated application log (`LOG_DAILY_DAYS=14`) |
| `AUCTION_SWEEP_LOOKBACK_DAYS`, `AUCTION_SWEEP_GRACE_MINUTES` | `7`, `15` | Safety window of `auction:repair-unsettled` |
| `NOTIFY_RESERVE_NOT_MET_EVENT` | `false` until Dot.Notify's catalogue has `auction.reserve_not_met` | E-mail copy of the reserve notice |
| `AUCTION_BID_MAX_PER_WINDOW`, `AUCTION_BID_WINDOW_SECONDS` | `20`, `60` | Bids and Buy Now presses per person per window |

## 5. Backups, restores and what is not covered here

Database backups and the restore drill are not part of this app (owner item, see the programme register). Photos live on the
private disk `storage/app/private/auction-images`; the originals are the only irreplaceable files (the copies are rebuilt on
demand). Back them up with the database.
