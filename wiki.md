---
title: Dot.Auction — Platform Wiki
version: 0.2.0
status: active
owners: [Auction Platform Lead]
platform-id: dot-auction
last-review: 2026-08-01
---

# Dot.Auction

Purpose: this is Dot.Auction's own knowledge home — owned and maintained by the Dot.Auction team. It describes what this platform is, what it stores, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-auction.md)

---

## 1. What Dot.Auction Is

Dot.Auction is the live-bidding platform of the Dot Ecosystem: sellers list items with a starting price and a fixed auction window, buyers bid in real time, and the platform tracks the current highest bid, enforces reserve prices, and settles the winner the moment the clock runs out. The tagline in our own README says it plainly — *"List items, place live bids, and win with confidence."*

**Status:** built and running as a Laravel application, not a blueprint. The repository has a working schema (`auctions`, `bids`, `auction_categories`, `watchlists`, `auction_items`), Eloquent models with real relationships and business logic, a broadcast event that pushes bids to listeners over WebSockets, and an authenticated seller dashboard. What is not yet built: buyer-facing bidding UI beyond the dashboard, auto-bid execution logic, dispute resolution, and the Knowledge Pack publishing pipeline described in §6. Treat the architecture and domain sections below as current shipped behavior; treat the roadmap in §7 as what comes next.

## 2. Architecture

| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP 8.4) |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Auth / accounts | Laravel Jetstream + Sanctum, team-based accounts |
| Realtime | Laravel Reverb (WebSocket bid broadcasting) |
| Database | PostgreSQL 16 — the shared ecosystem instance (`DB_DATABASE=infodot`) |
| Queue | Database-backed queue (Redis available for scale-out) |
| Search | Laravel Scout · Meilisearch (planned integration point) |
| AI | Anthropic Claude (`claude-sonnet-4-6`) via `ANTHROPIC_API_KEY` |

Dot.Auction runs on the ecosystem's shared PostgreSQL instance rather than an isolated database, and authenticates through the ecosystem SSO handoff (`EcosystemAuthController` at `/auth/ecosystem`, Sanctum tokens) rather than standing up its own identity system. This is a deliberate ecosystem-integration choice, not an artifact of early scaffolding — Dot.Auction is one of the platforms sharing the InfoDot/Dot data plane by design.

Real-time bidding is the architectural centerpiece: when a bid is placed, `App\Events\BidPlaced` broadcasts on a per-auction channel (`auction.{auction_id}`) carrying the new amount, bidder name, and updated current price, so every connected watcher sees the price move without polling.

## 3. Domain Entities

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| Auction | `auctions` | `seller_id`, `category_id`, `starting_price`, `reserve_price`, `current_price`, `buy_now_price`, `bid_increment`, `status`, `starts_at`/`ends_at` | Status lifecycle: `draft` → `active` → `ended`/`cancelled`. `isActive()` checks status *and* the current time window. `minimumBid()` derives the next legal bid from `current_price + bid_increment`. |
| Bid | `bids` | `auction_id`, `bidder_id`, `amount`, `is_winning`, `is_auto_bid` | One row per bid attempt; `is_winning` flags the current leader per auction. |
| AuctionCategory | `auction_categories` | `name`, `slug` | Simple taxonomy; auctions optionally belong to one. |
| Watchlist | `watchlists` | `user_id` + `auction_id` composite key | Buyer-side interest tracking, no extra payload yet. |
| AuctionItem | `auction_items` | `auction_id`, `name`, `condition`, `location` | Item-level detail attached to an auction (condition, physical location) — supports auctions that bundle multiple physical items under one listing. |

Relationships worth noting: `Auction::winningBid()` is a dedicated `HasOne` scoped to `is_winning = true`, kept separate from the full `bids()` history (`HasMany`, latest-first) so the UI can cheaply show "current leader" without scanning bid history.

## 4. Events Emitted

| Event | Trigger | Channel / consumers | Payload |
|---|---|---|---|
| `BidPlaced` (broadcast) | A bid is recorded on an active auction | Reverb channel `auction.{auction_id}` — anyone watching that auction's live view | `auction_id`, `amount`, `bidder` (name), `current_price` |

Only one domain event is wired today. The natural next events — auction closed/settled, reserve not met, watchlist notification, ecosystem-facing settlement handoff to Dot.Billing — are not yet implemented; see the roadmap.

## 5. Connecting to Dot.Brain

Dot.Auction participates in the ecosystem as a registered platform (`dot-auction`). Dot.Brain's ingested view of this platform — including the mechanism-scoping rules that govern what Auction is allowed to publish — lives at [`platforms/dot-auction.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-auction.md) in Dot.Brain. That document is authoritative for integration mechanics (Knowledge Pack schedule, entity mapping, manifest, worked round-trips); this wiki is authoritative for what Dot.Auction actually *is* and what's actually built.

The governing principle carried over from Dot.Brain's view, and honored here even though the publishing pipeline itself doesn't exist yet: **individual bids and bidder identity never leave this platform.** What Dot.Auction intends to publish, once the Knowledge Pack pipeline is built, is clearing-level and mechanism-level knowledge only:

| Payload type | Would contain |
|---|---|
| `observation` | Clearing-rate and price-vs-estimate aggregates, batched per settled auction, never per bid |
| `insight` | Mechanism-fit findings (which auction format clears which category best) |
| `outcome` | Verification of prior Dot.Brain recommendations (e.g., did switching a category to a different format move `auction.clearing_rate`) |
| `incident` | Mechanism failures or scoping-gate near-misses |

Nothing about an auction should publish until it settles (status reaches `ended`), and bid-level data should never be aggregated below a safe cohort size. These rules exist in Dot.Brain's document today; implementing them in code is open work here.

## 6. Roadmap

- [ ] Buyer-facing live bidding UI (Livewire component wired to the `BidPlaced` broadcast, beyond the current seller dashboard)
- [ ] Auto-bid execution (the `is_auto_bid` column exists on `bids`; no engine acts on it yet)
- [ ] Auction settlement job: on `ends_at` passing, resolve winner, flip `status` to `ended`, and emit a settlement event
- [ ] `auction.reserve.not_met` event when a lot ends below reserve
- [ ] Dispute resolution workflow (mentioned in the platform README, not yet modeled)
- [ ] Knowledge Pack publisher: settlement-triggered `observation` packs to Dot.Brain, respecting the never-publish-individual-bids rule
- [ ] Search integration (Scout + Meilisearch) for auction discovery
- [ ] Ecosystem settlement handoff to Dot.Billing on auction close

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.2.0 | 2026-08-01 | Auction Platform Lead | Rewrote wiki from actual codebase (Laravel 12 / Livewire 3 / Reverb) instead of a blueprint framing — documented shipped schema, models, and the `BidPlaced` broadcast event; cross-referenced Dot.Brain's mechanism-scoping rules for future Knowledge Pack work |
| 0.1.0 | 2026-08-01 | Auction Platform Lead | Initial placeholder wiki |

## Open Questions

- Should auto-bid execution run as a queued job per new bid, or a scheduled sweep — affects fairness guarantees under concurrent bidding.
- Where does the settlement job live: a Laravel scheduled command here, or a Dot.Billing-triggered webhook — affects who owns the "auction ended" moment of truth.
- Reserve-price confidentiality (per Dot.Brain's gate: reserves publish only as met/not-met rates, never values) needs to be enforced in the Knowledge Pack publisher once it exists, not left to convention.
