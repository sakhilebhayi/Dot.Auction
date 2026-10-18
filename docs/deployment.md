# Deploying Dot.Auction

Production target: `https://auction.infodot.co.za`, on the shared cPanel account that also runs the other Dot apps. This document describes the deploy pipeline in `.github/workflows/`. **Nothing here has been run against a host**: the workflows were written from the InfoDot and Dot.Agents pipelines, checked with actionlint and `bash -n`, and their host scripts were exercised only against a fake local host. The first real run is the first real test.

The pipeline is **inert** until you do the setup below: it only starts when someone dispatches it by hand, and it stops at its first SSH step until the secrets and the host exist. GitHub offers a `workflow_dispatch` workflow in the Actions tab only once the file is on the default branch (`main`); the trunk this pipeline is stacked on, `main`, is not merged there.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR, pushes to the trunk; called by `deploy.yml` | PHPUnit on PHP 8.4, in-memory SQLite. The release gate. No Pint, no asset build. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual: `confirm=adopt` once, then `confirm=deploy` | See "What a deploy does". |

Both manual workflows refuse to run from any branch except `main` (the runner builds the frontend and runs the gate from the branch you start it on; the host checks out that same branch). When the trunk lands on `main`, change `DEPLOY_BRANCH` at the top of both files to `main`.

## Read this first: what the trunk contains

The branch the host runs, `main`, is the unmerged real-time trunk. These open PRs are stacked on it (merge order, bottom first). Deploy the stack, not the trunk alone.

| PR | Branch | What it adds | Migrations / data |
|---|---|---|---|
| #3 | `fix/auction-bid-atomicity` | atomic bidding (row lock), idempotent settlement, reserve-decision guards | **rewrites data**: first demotes legacy duplicate winning bids (keeps the highest amount, latest id; rows are not deleted), then builds a partial unique index `bids(auction_id) WHERE is_winning`, plus two more indexes |
| #4 | `feat/sso-redeem` | signed InfoDot SSO redeem | `teams.infodot_team_uuid` |
| #6 | `feat/seller-listings` | seller listings, anti-snipe, buy-now, ending-soon notices, settlement hand-off | `auctions.snipe_extensions`, `watchlists.ending_soon_notified_at`, new `auction_settlements` |
| #7 | `feat/seller-listing-images` | auction photos on a private disk | none; files go to `storage/app/private/auction-images` |
| #8 | `feat/notify-events` | outbid / won / sold / ending-soon email events | none |

On the trunk alone bidding is **not atomic** (PR #3: two simultaneous bids can both win; the audit rated it critical), settlement is not idempotent, and SSO redeem only works against a database shared with InfoDot. **Do not deploy the trunk without #3 and #4.**

Migrations are not run by `adopt` (unless you ask), but **a normal `deploy` always runs them**. The rollback copy contains a SQLite file; it does **not** contain a PostgreSQL or MySQL database.

**Back up the database before the first migration run.** PR #3 changes existing `bids` rows and the PR itself says "review the legacy-winner demotion step before deploying". Photos (PR #7) live in `storage/app`, which the rollback copy includes, so the copy grows with the number of photos.

## Reverb does not run here

This app broadcasts over Reverb (`deploy/reverb.service` is for a VPS). Reverb is a long-lived websocket server and shared cPanel allows no daemons, so **the pipeline never starts, reloads or proxies it**. What happens instead:

- The frontend is built on the runner with no `VITE_REVERB_*` values. The bundle then never creates `window.Echo`, so browsers never open a websocket (checked in `resources/js/bootstrap.js`: it only initialises when `VITE_REVERB_APP_KEY` is set).
- What stops: bid panels stop updating on their own (BidPanel listens for echo:auction.{id},.bid.placed), so a bidder sees a new price only after a reload or the next action on the panel; the notification bell stops lighting up on its own. Bidding itself is server-side and atomic, so it stays correct.
- Nothing polls: the app has no `wire:poll` and no timers. A page reload (or the next user action) shows the current state. There is no polling fallback in the code to switch on.
- Set `BROADCAST_CONNECTION=log` in the host `.env`. With `reverb`, every broadcast job is queued for a server that does not exist and fails (the deploy prints a warning when it sees `reverb`). `.env.example` ships `reverb`.
- To get live updates later you need a host that can run `php artisan reverb:start` under a supervisor, a TLS websocket proxy, and a rebuild with `VITE_REVERB_*` set at build time. That is a separate piece of work, not a setting.

## Scheduler and queue (cron)

The pipeline installs these through the account's **shared crontab**, marked `# dot-auction`:

```
* * * * * cd <app folder> && <php> artisan schedule:run > storage/logs/cron-scheduler.log 2>&1 # dot-auction
* * * * * cd <app folder> && <php> artisan queue:work --stop-when-empty --tries=3 --timeout=60 --max-time=50 > storage/logs/cron-queue.log 2>&1 # dot-auction
```

`schedule:run` is needed because `routes/console.php` schedules `auction:settle-ended` and `auction:notify-ending-soon` every five minutes (`withoutOverlapping`, `onOneServer`: both take a cache lock, which `CACHE_STORE=database` provides). Without the line ended auctions are never settled: no winner is recorded, no seller is told. `queue:work` runs the queued `RecordSettlementForPayment` listener (the settlement row and the "sold" notice), `SendNotifyEvent` (PR #8) and the `BidPlaced` broadcast.

How the write is made safe (copied from the Dot.Agents/Dot.docs pipelines, after two incidents):

- The write takes the account-wide lock `~/.dot-crontab.lock` (`flock`, waits up to 120 s), because deploys of different apps can coincide and one crontab is shared by every Dot app.
- `crontab -l` can transiently answer *empty*. A read counts only if it is non-empty or the host says "no crontab for"; it is retried five times, and the pipeline **refuses to write** after five bad reads (an empty read once led a pipeline to install an empty crontab and kill Dot.Mines' scheduler for six weeks).
- Only lines carrying `# dot-auction` are replaced; every other line is kept. After the write the crontab is read back: the number of other lines must be unchanged and ours must be exactly 2, otherwise the previous crontab is written back and the run fails.
- If a hand-made cron line for this app exists (no marker) it is left alone and a warning says so: delete it in cPanel (Cron Jobs) or the scheduler and worker run twice.
- Each line writes its last output to `storage/logs/cron-scheduler.log` / `cron-queue.log` (overwritten every minute): read these first when a job seems not to run.
- `queue:restart` runs after the write so a worker that started before the release exits and the next minute's worker has the new code.
- The queue worker is cron-driven (`--stop-when-empty --max-time=50`, one per minute), not a daemon. Jobs wait up to a minute for a worker. Do not use the repository's `deploy/queue-worker.*`, `deploy/scheduler.*` files here: they are for a server with systemd or Supervisor.

## Prerequisites checklist

Do these once, in this order. A box ticked here means the item exists on the host or in GitHub, not just that you decided it.

**Domain and host**

- [ ] DNS for `auction.infodot.co.za` points at the cPanel account, and AutoSSL has issued a certificate (the smoke check requests `https://auction.infodot.co.za`).
- [ ] cPanel subdomain `auction.infodot.co.za` with its docroot at `~/auction.infodot.co.za`.
- [ ] MultiPHP: PHP 8.4 for that subdomain (`composer.json` needs `^8.3`, CI tests 8.4), and `php -v` over SSH shows the same version (cron and the pipeline run the CLI `php`). Extensions: the PDO driver you chose, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, plus `intl` and `bcmath` (CI installs both), and **`gd` with JPEG, PNG and WebP support** (PR #7 validates uploads by decoding them; without WebP, WebP uploads are refused).
- [ ] `composer`, `git`, `rsync` and `flock` exist for the SSH user (the pipeline fails with a clear message when `flock` is missing). There is no Node on the host and none is needed.
- [ ] An SSH key for GitHub Actions in the cPanel account's `authorized_keys`, and the host's key fingerprints for `DEPLOY_KNOWN_HOSTS`.

**Database**

- [ ] A database and user for this app, credentials in the host `.env`. Prefer **PostgreSQL**. Auction was run on SQLite and on PostgreSQL 16 (PR #3 ran eight concurrent bidders against it). On MySQL the partial unique index does not exist, so the row lock in `BidPlacer` is the only guard against two winners (PR #3 says so); no PR reports a MySQL run.

**The app folder (first install, by hand)**

The pipeline updates a live install; it does not create one. Layout, as on the other Dot apps on this account:

```
~/auction.infodot.co.za/            docroot (what the web server serves)
  index.php            shim: <?php require __DIR__.'/auction/public/index.php';
  .htaccess            Laravel rewrite rules
  build -> auction/public/build     (the deploy creates this link if it is missing)
  images -> auction/public/images ; apple-touch-icon.png, favicon-16x16.png, favicon-32x32.png, favicon.ico, robots.txt  (each -> auction/public/<name>)
  auction/                the app folder = DEPLOY_PATH (artisan is here)
```

- [ ] Put a copy of the code in `~/auction.infodot.co.za/auction/` (cPanel Git clone of `main`, or an upload; it need not be current: `adopt` replaces tracked files) and create `.env` there (skeleton below). `.env` must exist, or the deploy stops before touching anything.
- [ ] `php artisan key:generate --force` once on the host. Jetstream two-factor secrets are encrypted with it: changing it later breaks them.
- [ ] The docroot files above. Copy `public/.htaccess` to `~/auction.infodot.co.za/.htaccess`; the other files in `public/` are linked from the docroot (`ln -sfn auction/public/<name> ~/auction.infodot.co.za/<name>`).
- [ ] The app folder itself must not be downloadable. The smoke check requests `/auction/.env`, `/auction/artisan` and `/auction/database/database.sqlite` and fails the run on a 200. If it does, deny the folder (for example an untracked `.htaccess` in the app folder containing `Require all denied`; not tested on this host) and run again.
- [ ] Remove any hand-made cron lines for this app in cPanel (the pipeline installs its own).

**Host `.env` skeleton** (no secret values here; fill them on the host only)

```
APP_NAME="Dot.Auction"
APP_ENV=production
APP_KEY=                      # php artisan key:generate --force, on the host, once
APP_DEBUG=false
APP_URL=https://auction.infodot.co.za

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log      # not reverb: see "Reverb does not run here"
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_SCHEME=smtp              # or smtps, as the host's SMTP requires
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="no-reply@infodot.co.za"
MAIL_FROM_NAME="${APP_NAME}"

# SSO through InfoDot (the secret must equal ECOSYSTEM_SECRET_AUCTION in InfoDot's .env)
INFODOT_REDEEM_URL=https://infodot.co.za/api/ecosystem/redeem
INFODOT_PLATFORM_KEY=auction
INFODOT_SERVICE_SECRET=

# Platform switcher links: one DOT_<KEY>_URL per platform in config/ecosystem.php
DOT_INFODOT_URL=https://infodot.co.za
# DOT_<KEY>_URL=https://<key>.infodot.co.za for each app you link to

# Optional tuning: every AUCTION_* variable has a default in config/auction.php

# Email via Dot.Notify (blank = nothing is sent)
NOTIFY_INGEST_URL=
NOTIFY_INGEST_SECRET=
NOTIFY_PLATFORM_KEY=auction
```

Why these values: `QUEUE_CONNECTION`, `CACHE_STORE` and `SESSION_DRIVER` must be `database` (there is no Redis; this app's `.env.production.example` already says `database`). `APP_ENV=production` and `APP_DEBUG=false` are forced by every deploy anyway. The `DOT_*_URL` values matter: `config/ecosystem.php` defaults them to `https://<key>.infodot.app`, which is not where these apps run, and the platform switcher builds its links from them. Mail: with `MAIL_MAILER=log` (the default) verification and password-reset emails vanish; use the SMTP account the other Dot apps use.

**InfoDot (the SSO hub) and Dot.Notify**

- [ ] In **InfoDot's** `.env`: `ECOSYSTEM_SECRET_AUCTION` = a new random value (`openssl rand -hex 32`). In this app's `.env`: `INFODOT_SERVICE_SECRET` = the same value, `INFODOT_PLATFORM_KEY=auction`. Until the secret is set, SSO sign-in does not call InfoDot.
- [ ] Mark `auction` live in InfoDot's platform registry. InfoDot refuses to redeem tokens for platforms not marked live, so every sign-in would be refused with 403. (In this repo's copy of `config/ecosystem.php` each platform has an `active` flag; InfoDot's own registry is the authority.)
- [ ] Check that InfoDot's `ECOSYSTEM_TRUSTED_DOMAINS` (named in InfoDot's `docs/deployment-cpanel.md`) allows `auction.infodot.co.za`.
- [ ] Companies: the first sign-in by an InfoDot owner or admin creates the company's team here, linked by `teams.infodot_team_uuid`; a team that predates SSO is linked by hand with `php artisan infodot:link-team {team} {infodot-team-uuid}` (see the SSO PR).
- [ ] Email through Dot.Notify is **off** until `NOTIFY_INGEST_URL` (the full endpoint, `https://notify.infodot.co.za/api/ingest/events`) and `NOTIFY_INGEST_SECRET` are both set in this `.env`. The secret must equal `NOTIFY_INGEST_SECRET_AUCTION` in **Dot.Notify's** `.env`; the company must be linked in Notify (`php artisan infodot:link-team`) and must switch the events on in Notify's Connected apps page. Sends are queued, so the cron worker must be running. Events: `auction.outbid`, `auction.won`, `auction.sold`, `auction.ending_soon`.
- [ ] This app reads no `ANALYTICS_*` variables (the only analytics reference in `config/ecosystem.php` is the `DOT_ANALYTICS_URL` link target).

**GitHub**

- [ ] Repository secrets: `DEPLOY_HOST` (`auction.infodot.co.za`), `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`. `DEPLOY_PATH` is optional; set it to `/home/<user>/auction.infodot.co.za/auction` to skip discovery (without it the app is the one `artisan` within two levels of `~/auction.infodot.co.za`; zero or several is an error, not a guess). Do not set `DEPLOY_PATH` to a folder outside `~/auction.infodot.co.za`: the docroot is taken as its parent folder.
- [ ] No GitHub environment or required reviewer is configured by these files. If you want a second pair of eyes before a release, add a protection rule yourself.

## First deploy, in order

1. Tick the checklist above, then take a **database backup** in cPanel and copy `.env` (and `APP_KEY`) somewhere safe.
2. If the folder already holds a running copy of the app, run **Drift Report** (`drift.yml`, from `main`) and read it: how many tracked files differ, which are absent, host-only migrations.
3. Run **Deploy to Production** with `confirm=adopt`. It makes a rollback copy, turns the folder into a checkout of `origin/main` without deleting anything untracked (`.env`, `storage/`, uploads), installs dependencies, ships the build, forces `APP_DEBUG=false` and `APP_ENV=production`, **lists** pending migrations (it does not run them), rebuilds caches, installs the cron lines and checks the site.
4. Read the pending-migration list and the stacked-PR table above. Back up again if the list contains a data-changing migration.
5. Run **Deploy to Production** with `confirm=deploy` (or `adopt` with `migrate=migrate` if you want the takeover to migrate). It runs the CI gate first, then everything above, and always migrates.
6. Open the site, sign in through InfoDot, and watch `storage/logs/cron-queue.log` once to see a worker run.

## Every release after that

Run **Deploy to Production** with `confirm=deploy`, started from `main`. If a PR is merged into the trunk, the next run ships it.

## What a deploy does

1. **Check the inputs**: `confirm` must be `deploy` or `adopt`; the run must start from `main`.
2. **CI gate**: the whole of `ci.yml`. Red means nothing was touched. (`ci.yml` declares `cancel-in-progress` for its `ci-<ref>` group: a push to the trunk while a release's gate is running may cancel one of the two, and the release then goes red before it reaches the host. Run it again.)
3. **Build on the runner** (`npm ci && npm run build`, Node 22), then check `public/build/manifest.json`: every `resources/css|js/...` path an `@vite()` call asks for is a manifest key, and every built file exists and is non-empty.
4. **On the host**: a rollback copy `~/dot-auction-<mode>-<time>.tgz` (the newest three kept; excludes `vendor`, `node_modules`, `storage/logs`, `public/build`); maintenance mode; code to `origin/main` (an explicit refspec, so a single-branch checkout works); host-only migration files moved to `~/dot-auction-quarantine-<time>/`; `composer install --no-dev`; `.env` invariants; the staged build swapped into `public/build` and mirrored into the docroot's `build/`; migrations; targeted cache clears and rebuilds; `php artisan up`.
5. **Cron lines** (above).
6. **Smoke check from the runner**: `/` and `/login` answer 200, `/up` answers 200, every built CSS and JS file answers 200 from `/build/`, a `POST /up` (a harmless 405) does not return a debug page, and the app folder is not served. Each page is asked up to three times (the host's edge firewall sometimes refuses a runner IP with 403).

Never touched: `.env` (except forcing `APP_DEBUG` and `APP_ENV`), `storage/`, `database/` (including a SQLite file), uploads.

## If a run fails

- **Before maintenance mode** (inputs, CI gate, build, SSH, upload, the early host checks): the site is still serving the previous release. The log says so. Fix and re-run.
- **After maintenance mode started**: the site is left **down on purpose**, with the rollback copy's name in the log, rather than guessing at an automatic rollback. Read the log, fix the cause and re-run the workflow in the same mode (a clean run brings the site up), or restore by hand.
- **After `php artisan up`** (cron lines or smoke check): the site is serving the new release; the run is red because a cron line could not be written or a check failed. Read the message; the previous crontab is restored if verification failed.

## Rollback

Code and files only. Over SSH: put the site in maintenance mode, `tar xzf ~/dot-auction-<mode>-<time>.tgz -C <app folder>` (it restores tracked files, `.env`, `storage/` and a SQLite file as they were), `composer install --no-dev`, rebuild the caches, `php artisan up`. This pipeline always deploys the branch tip, so reverting a release for good means reverting the commit on `main` and deploying that.

**A migration is not undone by the rollback copy** unless the database is a SQLite file. Restore the database from your own backup, and read the data-changing migrations in the stacked-PR table before you do (several have a `down()` that is lossy or a no-op).

## How to read the log

- Lines starting `==` are the host script's own progress (rollback copy name, release before and after, forced invariants, pending migrations, docroot build state, crontab result).
- `::warning::` lines are things the pipeline saw and did not change: `APP_URL` is `http://`, `BROADCAST_CONNECTION=reverb`, `redis` or `sync` drivers, a stray unmarked cron line, a failed `queue:restart`.
- On the host: `storage/logs/laravel-*.log` or `laravel.log` (depending on `LOG_STACK`), `storage/logs/cron-scheduler.log`, `storage/logs/cron-queue.log`, and `php artisan queue:failed` for jobs that gave up. There is no `server-logs.yml` in this repo (InfoDot has one); use the cPanel terminal.
- The smoke check prints each URL and status. A 403 or 000 after three tries is the host or its firewall, not the app; a 500 on `/login` is the app.

## Known limits

- **Nothing was run against a host.** Untested on the real account: the `find` for the app folder, `rsync` to `public/build.incoming`, `flock`, the real `crontab`, cPanel's LiteSpeed in front of the app, the docroot wiring, the smoke URLs from a GitHub runner.
- The shared-crontab block was run against a fake `crontab` (empty first reads, other apps' lines, a stray line, a bad read-back); `flock` itself was not exercised (macOS has none), the real file lock is only as good as the reference pipelines that use it.
- No Reverb (above): no live updates, by design, until a host that can run it exists.
- No scheduler watchdog and no `/up/scheduler` probe (Dot.Agents has both); a dead cron is found by reading `cron-scheduler.log` or by a missing effect.
- No automated database backup, and no restore automation. The pipeline neither takes nor verifies a database backup; it prints the driver and warns.
- A deploy always migrates; there is no "deploy without migrating" except in `adopt`.
- A release deploys the branch tip, not a chosen commit or tag.
- One server, one crontab, one queue: the worker count is whatever `* * * * *` and `--max-time=50` give you.
- `ci.yml` is the other PR's file and is not changed here. It runs PHPUnit only; it does not run Pint, PHPStan or an asset build, and it never touches Postgres or MySQL. No stacked PR reports a MySQL run.
- Photos are stored as uploaded: EXIF, including GPS, is kept (PR #7 notes).
- `AutoBidEngine` is dead code and is not wired in; proxy bidding does not exist in production.
- There is no payment provider: settlement records an `awaiting_payment` row and says so to the winner.
