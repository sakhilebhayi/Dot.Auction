# Auction Settlement + Reserve-Not-Met Approval Gate — Design Spec

## Context

This spec is part of the ecosystem-wide Autonomy & Owner-Independence Program
(per [brain.autonomy.md](https://github.com/sakhilebhayi/Dot.Brain/blob/main/brain.autonomy.md)
§2), applied here to Dot.Auction.

**The platform audit was checked against real code and found accurate.**
[`Dot.Brain/platforms/dot-auction.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-auction.md)
reports zero background automation on this platform — confirmed directly:
`app/Console/Commands`, `app/Jobs`, and `app/Listeners` do not exist;
`bootstrap/app.php` has no `->withSchedule()` call; `routes/console.php`
contains only the stock `inspire` command. There is nothing today for a
Level 1 process to run on, and consequently no Level 2 gate either (nothing
automated exists to need one).

**This is not an invented gap.** The platform's own [`wiki.md`](wiki.md) §6
Roadmap already names exactly this as unbuilt, in its own words:

> - [ ] Auction settlement job: on `ends_at` passing, resolve winner, flip
>   `status` to `ended`, and emit a settlement event
> - [ ] `auction.reserve.not_met` event when a lot ends below reserve

Two notification classes already exist, written and tested, waiting for a
trigger: `App\Notifications\AuctionWonNotification` and
`App\Notifications\AuctionEndingSoonNotification` (this spec wires the
former; the latter's "ending soon" sweep is a separate feature — see Out of
Scope).

## Goal

Build the settlement job the roadmap already scopes, split honestly along
the one real judgment call it contains: resolving an auction whose top bid
clears the reserve is mechanical (the seller already agreed to sell at or
above that price); resolving one whose top bid falls short is the seller's
decision to make, not the system's.

## Design

### 1. Migration — widen `auctions.status`

`auctions.status` is currently `enum('draft','active','ended','cancelled')`.
Add `pending_review`: an auction whose top bid is below reserve moves here
instead of `ended`, so it's structurally distinguishable from every other
terminal or in-flight state (`isActive()` already excludes anything that
isn't `active`, so no bidding-window logic needs to change).

Uses this program's established portable technique: conditionally drop the
pgsql `CHECK` constraint (`auctions_status_check`) and widen the column to a
plain string (SQLite has no such constraint to drop, so its migration path
is a no-op there beyond the column type change, handled automatically by
Laravel's schema builder rebuilding the table). Matches
`Dot.Tutor/database/migrations/2026_08_09_000001_widen_tutor_sessions_status_column.php`'s
exact shape.

### 2. `reserve_not_met_proposals` table + `ReserveNotMetProposal` model

One row per reserve-not-met event, created the moment settlement finds a top
bid below reserve:

| Column | Type | Notes |
|---|---|---|
| `auction_id` | FK → `auctions`, cascade delete | |
| `bid_id` | FK → `bids`, cascade delete | the top (`is_winning`) bid at settlement time |
| `reserve_price` | decimal(12,2) | snapshotted — `auctions.reserve_price` could change later; the proposal must show what the reserve actually was when this fired |
| `status` | string, default `pending` | `pending` / `accepted` / `rejected` |
| `decided_at` | nullable timestamp | |
| timestamps | | |

No `decided_by` column — the only valid reviewer is the auction's own
seller (see Policy below), so who decided is already implied by which
auction the proposal belongs to; recording it again would be redundant.

### 3. `app/Console/Commands/SettleEndedAuctions.php` (`auction:settle-ended`)

The platform's first-ever scheduled command. Logic lives directly in the
command class — this repo has no `app/Services` or `app/Actions` (custom)
layer to route through; `AuctionController`/`BidPanel` both keep domain
logic inline, and this follows that same convention rather than importing
a heavier layering this codebase doesn't otherwise use.

```
for each Auction where status = 'active' and ends_at <= now():
    topBid = auction.winningBid  (existing relation: bids where is_winning = true)

    if topBid is null:
        auction.status = 'ended'                      # no bids at all — nothing to decide
        dispatch AuctionSettled(auction, winningBid: null)

    elif auction.reserveMet():                          # existing method: current_price >= reserve_price, or no reserve set
        auction.status = 'ended'                       # reserve cleared (or none set) — Level 1
        topBid.bidder.notify(AuctionWonNotification(auction))
        dispatch AuctionSettled(auction, winningBid: topBid)

    else:
        auction.status = 'pending_review'               # reserve not met — Level 2, stop here
        ReserveNotMetProposal::create(auction_id, bid_id: topBid.id, reserve_price: auction.reserve_price)
        dispatch ReserveNotMet(proposal)
```

Each auction is processed inside its own try/catch — one bad row is logged
and skipped, not allowed to abort the sweep for every other due auction
(matches `DetectRetentionPurgeCandidates`'s established per-row resilience
convention from this program's earlier work).

Scheduled in `routes/console.php` — also the platform's first schedule
entry — at `->everyFiveMinutes()->withoutOverlapping()`.

### 4. Events

`app/Events/AuctionSettled.php` — `(Auction $auction, ?Bid $winningBid)`,
plain `Dispatchable` (not `ShouldBroadcast` — unlike `BidPlaced`, nothing in
the UI needs a live push for this yet; a real-time "your auction just
settled" toast is a reasonable future enhancement, out of scope here).

`app/Events/ReserveNotMet.php` — `(ReserveNotMetProposal $proposal)`, same
shape. This is the literal `auction.reserve.not_met` event wiki.md §6 names
as owed.

### 5. `app/Policies/ReserveNotMetProposalPolicy.php`

```php
public function review(User $user, ReserveNotMetProposal $proposal): bool
{
    return $user->id === $proposal->auction->seller_id;
}
```

Mirrors `AuctionPolicy::bid()`'s existing `$user->id === $auction->seller_id`
ownership check exactly — no new role or flag, the seller is already the
established owner concept this platform authorizes against everywhere else.

### 6. `App\Livewire\Auctions\ReserveDecisionPanel`

Mirrors `BidPanel`'s architecture: mounted with a model
(`mount(ReserveNotMetProposal $proposal)`), inline logic in its action
methods (no separate Action/DTO class, matching this repo's existing
directness), `Gate::authorize()` as each method's first line (matching
`AuctionController`'s convention).

```php
public function accept(): void
{
    Gate::authorize('review', $this->proposal);

    $auction = $this->proposal->auction;
    $bid = $this->proposal->bid;

    $auction->update(['status' => 'ended']);
    $bid->bidder->notify(new AuctionWonNotification($auction));
    $this->proposal->update(['status' => 'accepted', 'decided_at' => now()]);
}

public function reject(): void
{
    Gate::authorize('review', $this->proposal);

    $this->proposal->auction->update(['status' => 'ended']);
    $this->proposal->update(['status' => 'rejected', 'decided_at' => now()]);
}
```

Rejecting settles the auction unsold — no winner, no notification. There is
no third option (e.g. relisting): relisting means creating a new auction,
a distinct feature this spec doesn't build (see Out of Scope).

Shown on the seller's own dashboard (`dashboard.blade.php`, already
seller-scoped by `auth()->id()` throughout its controller closure) for any
of their auctions currently `pending_review`.

## Testing Strategy

- `tests/Feature/Console/SettleEndedAuctionsCommandTest.php`: no-bids case
  (ends unsold, no proposal), reserve-met case (ends, winner notified,
  `AuctionSettled` dispatched), no-reserve case (same as reserve-met, since
  a null reserve always "clears"), reserve-not-met case (`pending_review`,
  proposal created with the right snapshot, `ReserveNotMet` dispatched, no
  notification sent yet), an `active` auction not yet past `ends_at` is
  left untouched.
- `tests/Feature/Auctions/ReserveDecisionPanelTest.php`: seller can accept
  (auction ends, winner notified, proposal `accepted`), seller can reject
  (auction ends unsold, proposal `rejected`), a non-seller (including
  another seller entirely) is forbidden from either action.

Follows this repo's own established test convention: `Auction::create()`/
`Bid::create()` directly (no factories exist for these models, and none are
introduced here — matches `BidPanelTest`/`AuctionShowTest`/etc. exactly).

## Out of Scope

- The `AuctionEndingSoonNotification` watcher sweep (wiki.md's separate,
  unrelated roadmap line) — not part of settlement.
- Auto-bid execution (`is_auto_bid` column, also separately unbuilt per
  wiki.md §6) — unrelated to settlement.
- Relisting an auction whose reserve wasn't met, or any seller action
  beyond accept/reject.
- Real-time broadcast of `AuctionSettled`/`ReserveNotMet` over Reverb.
- The Knowledge Pack publisher settlement handoff to Dot.Brain (wiki.md §6,
  separately unbuilt, depends on this settlement job existing first but is
  its own follow-on piece).
- Ecosystem settlement handoff to Dot.Billing (same — a real future step,
  not this one).
