<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The cheap, polling-friendly picture of one auction (R-42 F-04, backend half).
 *
 * Production runs without Reverb, so the bid panel polls. Each poll must cost one indexed query at most: the
 * auction row, its bid count and the leading bidder's id come back in a single SELECT (two correlated sub-selects on
 * the bids(auction_id ...) index), and that row is cached for ONE second per auction, shared by every viewer. What
 * depends on the viewer (leading_bidder_is_me) and on the clock (status, seconds_remaining, server_now) is computed
 * on each call from the cached values, so a cached row never serves a stale "active" for an auction that has closed.
 *
 * Contract, also in docs/R42_DECISIONS.md:
 *
 *   auction_id           int
 *   status               'scheduled' | 'active' | 'ended' | 'pending_review' | 'cancelled' | 'draft'
 *                        ('ended' is also reported as soon as ends_at has passed, before the settlement job runs)
 *   current_price        "300.00"          decimal string, never a float
 *   current_price_cents  30000
 *   minimum_bid          "310.00"          what the next bid must be at least
 *   bid_count            int
 *   starts_at, ends_at   ISO 8601 in UTC with a Z ("2026-11-08T16:00:00Z")
 *   seconds_remaining    int|null          until ends_at by the SERVER clock (null unless active); never negative
 *   leading_bidder_is_me bool              false for guests and for everyone but the current winner
 *   server_now           ISO 8601 UTC      the server clock at the moment of the call
 *   server_now_ms        int               the same, epoch milliseconds
 *   poll_seconds         int               how often a client should poll (config auction.realtime.poll_seconds)
 *   realtime             bool              true when Reverb pushes updates (config auction.realtime.enabled)
 *
 * A client countdown should NOT use the device clock. Compute offset = server_now_ms - Date.now() when a snapshot
 * arrives and count down to Date.parse(ends_at) with (Date.now() + offset), or simply count down seconds_remaining
 * from the moment the snapshot was received.
 */
class AuctionSnapshot
{
    public const CACHE_SECONDS = 1;

    /**
     * @return array<string, mixed>
     */
    public function for(Auction|int $auction, ?User $viewer = null): array
    {
        $id = $auction instanceof Auction ? $auction->getKey() : $auction;

        /** @var array<string, mixed>|null $row */
        $row = Cache::remember(self::cacheKey($id), self::CACHE_SECONDS, fn () => $this->read($id));

        $now = now();

        // A draft is its seller's alone: to anyone else (or a guest) it is exactly as absent as an id that was never used,
        // so the polling endpoint cannot be used to count or read other sellers' drafts.
        if ($row !== null && $row['status'] === 'draft' && ($viewer === null || (int) $viewer->getKey() !== (int) $row['seller_id'])) {
            $row = null;
        }

        if ($row === null) {
            return [
                'auction_id' => $id,
                'status' => 'missing',
                'server_now' => $now->toIso8601ZuluString(),
                'server_now_ms' => (int) $now->getPreciseTimestamp(3),
                'poll_seconds' => $this->pollSeconds(),
                'realtime' => $this->realtime(),
            ];
        }

        $startsAt = Carbon::parse($row['starts_at'], 'UTC');
        $endsAt = Carbon::parse($row['ends_at'], 'UTC');

        $status = match (true) {
            $row['status'] === 'active' && $startsAt->isFuture() => 'scheduled',
            $row['status'] === 'active' && $endsAt->lessThanOrEqualTo($now) => 'ended',
            default => $row['status'],
        };

        $current = Auction::toCents($row['current_price']);

        return [
            'auction_id' => $id,
            'status' => $status,
            'current_price' => Auction::fromCents($current),
            'current_price_cents' => $current,
            'minimum_bid' => Auction::fromCents($current + Auction::toCents($row['bid_increment'])),
            'bid_count' => (int) $row['bid_count'],
            'starts_at' => $startsAt->toIso8601ZuluString(),
            'ends_at' => $endsAt->toIso8601ZuluString(),
            'seconds_remaining' => $status === 'active' ? max(0, (int) $now->diffInSeconds($endsAt, false)) : null,
            'leading_bidder_is_me' => $viewer !== null
                && $row['leading_bidder_id'] !== null
                && (int) $row['leading_bidder_id'] === (int) $viewer->getKey(),
            'server_now' => $now->toIso8601ZuluString(),
            'server_now_ms' => (int) $now->getPreciseTimestamp(3),
            'poll_seconds' => $this->pollSeconds(),
            'realtime' => $this->realtime(),
        ];
    }

    /** Drop the cached row so the next poll sees a bid or a close at once (called after a commit). */
    public static function forget(int $auctionId): void
    {
        Cache::forget(self::cacheKey($auctionId));
    }

    public static function cacheKey(int $auctionId): string
    {
        return 'auction-snapshot:'.$auctionId;
    }

    /**
     * The one query. Plain scalars only, so the cached value is small and serialises on every cache store.
     *
     * @return array<string, mixed>|null
     */
    private function read(int $id): ?array
    {
        $auction = Auction::query()
            ->whereKey($id)
            ->select(['id', 'seller_id', 'status', 'current_price', 'bid_increment', 'starts_at', 'ends_at'])
            ->withCount('bids')
            ->addSelect(['leading_bidder_id' => Bid::query()
                ->select('bidder_id')
                ->whereColumn('bids.auction_id', 'auctions.id')
                ->where('is_winning', true)
                ->limit(1)])
            ->first();

        if ($auction === null) {
            return null;
        }

        return [
            'seller_id' => (int) $auction->seller_id,
            'status' => (string) $auction->status,
            'current_price' => (string) $auction->current_price,
            'bid_increment' => (string) $auction->bid_increment,
            'starts_at' => $auction->starts_at->toIso8601String(),
            'ends_at' => $auction->ends_at->toIso8601String(),
            'bid_count' => (int) $auction->bids_count,
            'leading_bidder_id' => $auction->leading_bidder_id === null ? null : (int) $auction->leading_bidder_id,
        ];
    }

    private function pollSeconds(): int
    {
        return max(1, (int) config('auction.realtime.poll_seconds', 10));
    }

    private function realtime(): bool
    {
        return (bool) config('auction.realtime.enabled', true);
    }
}
