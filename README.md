<div align="center">

<img src="public/images/logo.png" alt="Dot.Auction" width="200" />

<br /><br />

**List items, place live bids, and win with confidence.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [Dot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `auction.infodot.app`

</div>

---

## What is Dot.Auction?

Dot.Auction is the live-bidding platform in the Dot ecosystem. Sellers list items with a starting
price and a fixed auction window; buyers browse, watch, and bid in real time, with automatic
highest-bid tracking and reserve-price confidentiality.

**Status:** built and running as a Laravel application. See [`wiki.md`](wiki.md) for the full,
kept-honest breakdown of what's actually implemented vs. still roadmap.

## Core Features (implemented today)

- Real auction schema: auctions, bids, categories, watchlist, and auction items
- Live bid broadcasting via Laravel Reverb (`App\Events\BidPlaced` on `auction.{id}`)
- Seller operations dashboard — active lots, bids received, categories, watchlist volume, and an
  "ending soon" widget
- Buyer-facing marketplace: search/filter auctions by title, category, and status (`/auctions`)
- Auction detail + live bidding page (`/auctions/{auction}`), including the previously
  unrendered `BidPanel` Livewire component
- Reserve-price confidentiality: buyer-facing views only ever see "reserve met / not met" — the
  actual reserve amount is never sent to a non-seller
- Buyer watchlist toggle (add/remove an auction from your watchlist)
- In-app notification bell (Laravel's `database` notification channel); an outbid notification
  fires automatically the moment someone else outbids you
- Dark / light mode toggle (Tailwind class-based strategy, persisted per browser)
- Ecosystem SSO from the Dot hub

> **Not yet built:** auto-bid execution (the `is_auto_bid` column exists, no engine acts on it),
> an auction settlement job (nothing flips `status` to `ended` automatically when `ends_at`
> passes), dispute resolution, and the Knowledge Pack publisher to Dot.Brain.
> `AuctionWonNotification` and `AuctionEndingSoonNotification` classes exist but have no
> automatic trigger yet — they're ready for the settlement job / scheduled sweep once those
> exist. See `wiki.md` §6 for the full roadmap. Earlier drafts of this README described
> fictitious models (`AuctionLot`, `AuctionResult`) that were never implemented — the real
> domain models are listed below.

## Domain Models

- **Auction** — item/lot listed for bidding, with starting/reserve/current/buy-now price and a
  status lifecycle (`draft` → `active` → `ended`/`cancelled`)
- **Bid** — placed bid with amount, `is_winning` flag, and `is_auto_bid` flag (unused today)
- **AuctionCategory** — simple taxonomy
- **Watchlist** — buyer-side interest tracker (`user_id` + `auction_id`)
- **AuctionItem** — item-level detail attached to an auction (condition, location)

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared `infodot` database across the ecosystem) |
| Realtime | Laravel Reverb (bid broadcasting) |
| Auth | Laravel Jetstream + Sanctum, team-based accounts (InfoDot SSO) |
| AI | Anthropic Claude (`ANTHROPIC_API_KEY`, `claude-sonnet-4-6`) — config only, not called by any auction logic yet |
| Queue | Database-backed queue |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Auction.git
cd Dot.Auction
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and
> `APP_URL=https://auction.infodot.app`. Users authenticated through InfoDot gain access
> automatically via the Sanctum handoff at `/auth/ecosystem`.

### Running Tests

```bash
php artisan test
```

Feature tests use an in-memory SQLite connection (see `phpunit.xml`) and Laravel's
`RefreshDatabase` trait — no shared Postgres instance required to run them.

## 🚢 Deployment

### Production Checklist

1. **Set environment** (see `.env.production.example` for the full template)
   ```bash
   APP_ENV=production
   APP_DEBUG=false
   ```

2. **Install dependencies (no dev)**
   ```bash
   composer install --optimize-autoloader --no-dev
   npm ci
   ```

3. **Build frontend assets**
   ```bash
   npm run build
   ```

4. **Cache configuration**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```

5. **Run migrations**
   ```bash
   php artisan migrate --force
   ```

6. **Set storage permissions**
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

7. **Start the queue worker** (use `deploy/queue-worker.service` for systemd or
   `deploy/queue-worker.supervisord.conf` for Supervisor) — broadcast jobs (`BidPlaced`,
   `OutbidNotification`) and the `auction:settle-ended` scheduled sweep both run through this.
   ```bash
   php artisan queue:work database --tries=3 --timeout=90
   ```

8. **Start the Reverb WebSocket server** (use `deploy/reverb.service` for systemd or
   `deploy/reverb.supervisord.conf` for Supervisor) — never run this as a bare foreground command
   in production, it needs the same process supervision as the queue worker.
   ```bash
   php artisan reverb:start
   ```
   Binds to `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` (loopback-only by default in
   `.env.production.example`) — it is not meant to be reachable directly from the internet. See
   "WebSocket Reverse Proxy" below for how browsers actually reach it over `wss://`.

9. **Verify the real-time chain is healthy**
   ```bash
   curl https://auction.infodot.app/up/realtime
   ```
   Reports broadcasting config, queue connection, and the `reverb:start` process independently —
   see `app/Http/Controllers/RealtimeHealthController.php`.

### Web Server Configuration

#### Nginx

```nginx
server {
    listen 80;
    server_name auction.infodot.app;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name auction.infodot.app;
    root /var/www/dot-auction/public;

    ssl_certificate     /etc/letsencrypt/live/auction.infodot.app/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/auction.infodot.app/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Only Reverb's client-facing path (/app/{key}, what Echo/pusher-js
    # connects to) is proxied here. Its server-to-server publish API
    # (/apps/{id}/events etc.) is deliberately NOT exposed publicly --
    # config/broadcasting.php's reverb connection talks to it directly over
    # the internal REVERB_SERVER_HOST/PORT instead, so it never needs to be
    # reachable from outside this box.

    # WebSocket Reverse Proxy -- proxies wss://auction.infodot.app/app/... to
    # the internal reverb:start process (REVERB_SERVER_HOST/PORT, loopback-only
    # by default). The Upgrade/Connection headers are what turn this from a
    # plain HTTP proxy into a WebSocket one; without them the client's
    # protocol upgrade handshake fails and Echo falls back to polling or
    # errors outright. Long read_timeout because these are meant to be
    # long-lived connections, not request/response.
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }
}
```

#### Apache

Requires `mod_proxy`, `mod_proxy_wstunnel`, and `mod_ssl` enabled (`a2enmod proxy proxy_wstunnel ssl`).

```apache
<VirtualHost *:80>
    ServerName auction.infodot.app
    Redirect permanent / https://auction.infodot.app/
</VirtualHost>

<VirtualHost *:443>
    ServerName auction.infodot.app
    DocumentRoot /var/www/dot-auction/public

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/auction.infodot.app/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/auction.infodot.app/privkey.pem

    <Directory /var/www/dot-auction/public>
        AllowOverride All
        Require all granted
    </Directory>

    # WebSocket Reverse Proxy -- see the Nginx /app block above for what
    # this achieves and why. ProxyPass's own ws:// scheme (rather than
    # http://) is what makes mod_proxy_wstunnel handle the Upgrade
    # handshake instead of treating this as a normal HTTP proxy.
    ProxyPass        /app ws://127.0.0.1:8080/app
    ProxyPassReverse /app ws://127.0.0.1:8080/app
</VirtualHost>
```

## Ecosystem

**Dot.Auction** is one of the platforms in the Dot ecosystem, connected via shared PostgreSQL and
Sanctum SSO. Visit [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem-wide
knowledge repo, and [`wiki.md`](wiki.md) for this platform's own source of truth.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
