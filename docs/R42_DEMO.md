# R-42 demo world (local only)

A believable, fictional South African marketplace for design and QA work. Nothing here is real: people,
lots and prices are invented, and every photo is a flat colour labelled `DEMO IMAGE`.

## Build it

```bash
cp .env.example .env && php artisan key:generate                       # once
export DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/audit.sqlite     # any local database works
touch database/audit.sqlite && php artisan migrate
php artisan auction:demo-seed            # builds the world; does nothing if it is already complete
php artisan auction:demo-seed --fresh    # removes the demo accounts and everything they own, then rebuilds
```

* **Refuses to run in production**: the command exits with an error, and the seeder class throws if called
  directly (`db:seed --class=AuctionDemoSeeder`).
* **Idempotent**: the last account it creates (`zanele.mthembu@demo.auction.test`) is the completion
  marker. If it exists, a plain run changes nothing. If an earlier run died half-way the marker is missing,
  so the next run removes the strays and rebuilds.
* **Timed relative to now.** "Ends in 40 minutes" is true only at seed time. Rebuild with `--fresh` when you
  sit down to work. Ended lots, bids and settlements are back-dated.
* **Built through the real services**: `ListingManager` (create, publish, cancel), `BidPlacer` (including the
  anti-snipe extension), `BuyNowPurchaser`, `AuctionSettler` (winner, reserve-not-met), `SettlementLifecycle`
  (cancel), `AuctionImageManager` (photos on the private disk). Only timestamps and the paid and overdue
  settlement states are written directly, because a payment gateway cannot be called.
* It forces `QUEUE_CONNECTION=sync` and `BROADCAST_CONNECTION=null` for its own run, so no worker and no
  Reverb are needed to seed.

## Sign in

Every account has the password `password`. In `local` and `testing` only, a route signs you in without it:

```
GET /dev/login/{id or email}                 -> /dashboard
GET /dev/login/{id or email}?to=/auctions    (the target must be a path on this site)
GET /dev/logout
```

The routes live in a `// --- dev ---` block at the end of `routes/web.php` and are **not registered**
unless `app()->environment('local', 'testing')`. A test pins that they are absent in production.

| Key | Account | Role in the story |
|---|---|---|
| thandi | `thandi.mokoena@demo.auction.test` | Main seller, 13 lots in every status; the stand mixer ended below reserve and waits for her decision |
| pieter | `pieter.merwe@demo.auction.test` | Second seller, 12 lots; the painting ended below reserve and waits for his decision |
| lerato | `lerato.dlamini@demo.auction.test` | Bidder; holds a cancelled sale; watches several lots |
| sipho | `sipho.nkosi@demo.auction.test` | Winner: one sale awaiting payment, one overdue, one paid |
| aisha | `aisha.patel@demo.auction.test` | Bidder; one paid sale |
| johan | `johan.botha@demo.auction.test` | Bidder; bought the camera with Buy Now (awaiting payment) |
| naledi | `naledi.khumalo@demo.auction.test` | Platform operator (payment operations page) |
| zanele | `zanele.mthembu@demo.auction.test` | Brand-new user with no activity at all |

## What exists

25 lots: 3 drafts; 1 scheduled; 7 live (one with a reserve met, one with a reserve not yet met, one ending in
about 40 minutes, one whose close was extended twice by late bids, two with Buy Now); ended lots with a winner;
2 ended below reserve and waiting for the seller (`pending_review`) and 1 the seller rejected; 1 closed by
Buy Now; 1 ended with no bids; 2 cancelled. One to three placeholder photos on most lots. Item rows (condition,
location) on a few. Watchlist rows for four buyers. About 50 notifications (outbid, won, sold, ending soon,
paid, overdue, cancelled), roughly half read.

Settlements in every state: awaiting payment (2), paid (2), payment overdue (1), cancelled (1). The operator
page also has a started checkout, a failed attempt and one payment event that matched no sale (needs an
operator).

## Showing the payment screens

With no Paystack key every page says "Payment opens soon" and what will be owed (`App\Support\SettlementPosition`); the seeded overdue sale reads as waiting, there is no Pay now, and no "deadline passed" history is written. Seed again after setting the key to get the payment-opened and deadline history (and the overdue notices) too. To see the Pay now screens
set `PAYSTACK_SECRET_KEY=sk_test_demo` (a fake test key: pressing Pay now then fails at Paystack, which shows
the gateway-error state). No real key is ever needed or wanted here.
