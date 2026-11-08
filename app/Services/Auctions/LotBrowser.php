<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides which lots belong to a browse filter, how they are sorted and which per-viewer facts ride
 * along (do I lead, did I bid, am I watching). The browse page, the watchlist page and the dashboard all read through
 * it, so "Sold" means the same thing everywhere and a list of any length costs the same number of queries.
 *
 * A filter ("state") is the same vocabulary as DESIGN.md's status table, judged on the clock and the stored status the
 * way App\Support\Display::lotState() judges it:
 *
 *   live       open for bids now (includes the lots that are closing soon)
 *   ending     open and inside the closing-soon window (config auction.ending_soon_window_minutes)
 *   scheduled  published, not open yet
 *   pending    ended below the reserve, the seller is deciding
 *   sold       ended with a winning bid the seller did not reject
 *   unsold     ended with no bid, or the seller rejected the top bid
 *   cancelled  cancelled
 *
 * Nothing here reads reserve_price.
 */
final class LotBrowser
{
    /** @var array<string, string> state key => the word on the tab (the same words as the status vocabulary) */
    public const STATES = [
        'live' => 'Live',
        'ending' => 'Closing soon',
        'scheduled' => 'Scheduled',
        'pending' => 'Awaiting decision',
        'sold' => 'Sold',
        'unsold' => 'Not sold',
        'cancelled' => 'Cancelled',
    ];

    /** @var array<string, string> sort key => label */
    public const SORTS = [
        'ending' => 'Ending soonest',
        'newest' => 'Newest',
        'price_asc' => 'Price, low to high',
        'price_desc' => 'Price, high to low',
        'bids' => 'Most bids',
    ];

    public const DEFAULT_SORT = 'ending';

    /** Old query strings (?status=active) still land somewhere sensible. */
    private const LEGACY = ['active' => 'live', 'pending_review' => 'pending'];

    private const WINNING = 'exists (select 1 from bids where bids.auction_id = auctions.id and bids.is_winning = ?)';

    private const REJECTED = "exists (select 1 from reserve_not_met_proposals rp where rp.auction_id = auctions.id and rp.status = 'rejected')";

    public static function normaliseState(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        $state = self::LEGACY[$state] ?? $state;

        return array_key_exists($state, self::STATES) ? $state : null;
    }

    public static function normaliseSort(mixed $sort): string
    {
        return is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * Lots a person may see in a list: everything but drafts.
     *
     * @return Builder<Auction>
     */
    public function base(?string $search = null, ?int $category = null): Builder
    {
        return Auction::query()
            ->browsable()
            // Case-insensitive on every database (PostgreSQL's LIKE is not), with % and _ taken literally.
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->whereRaw(
                'lower(title) like ? escape ?',
                ['%'.addcslashes(mb_strtolower($search), '\\%_').'%', '\\'],
            ))
            ->when($category !== null, fn (Builder $q) => $q->where('category_id', $category));
    }

    /**
     * Narrow to one state. Time-dependent, so $now is passed in (and fixed for the whole request).
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function inState(Builder $query, ?string $state, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        if ($state === null) {
            return $query;
        }

        return match ($state) {
            'live' => $query->where('status', 'active')->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'ending' => $query->where('status', 'active')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
                ->where('ends_at', '<=', $now->copy()->addMinutes($this->windowMinutes())),
            'scheduled' => $query->where('status', 'active')->where('starts_at', '>', $now),
            'pending' => $query->where('status', 'pending_review'),
            'sold' => $query->where('status', 'ended')
                ->whereRaw(self::WINNING, [true])
                ->whereRaw('not '.self::REJECTED),
            'unsold' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $e) => $e->where('status', 'ended')->where(fn (Builder $w) => $w
                    ->whereRaw('not '.self::WINNING, [true])->orWhereRaw(self::REJECTED)))
                ->orWhere(fn (Builder $e) => $e->where('status', 'active')->where('ends_at', '<=', $now))),
            'cancelled' => $query->where('status', 'cancelled'),
            default => $query,
        };
    }

    /**
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function sorted(Builder $query, string $sort, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return match ($sort) {
            'newest' => $query->orderByDesc('auctions.id'),
            'price_asc' => $query->orderBy('auctions.current_price')->orderBy('auctions.id'),
            'price_desc' => $query->orderByDesc('auctions.current_price')->orderByDesc('auctions.id'),
            'bids' => $query->orderByDesc('bids_count')->orderBy('auctions.ends_at')->orderBy('auctions.id'),
            default => $this->soonestFirst($query, $now),
        };
    }

    /**
     * Open lots first by the clock, then the ones not open yet, then the ones waiting on a seller, then closed lots by
     * the most recent close, cancelled last. Within the first two groups the lot that ends soonest comes first.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function soonestFirst(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $rank = "case when auctions.status = 'active' and auctions.starts_at <= ? and auctions.ends_at > ? then 0"
            ." when auctions.status = 'active' and auctions.starts_at > ? then 1"
            ." when auctions.status = 'pending_review' then 2"
            ." when auctions.status = 'ended' or (auctions.status = 'active' and auctions.ends_at <= ?) then 3 else 4 end";

        return $query
            ->orderByRaw($rank, [$now, $now, $now, $now])
            // Groups 0 and 1: soonest close first. The expression is null elsewhere, so the next key orders the rest.
            ->orderByRaw('case when ('.$rank.') <= 1 then auctions.ends_at end asc', [$now, $now, $now, $now])
            ->orderByDesc('auctions.ends_at')
            ->orderByDesc('auctions.id');
    }

    /**
     * Everything a lot card needs, in the one query: the bid count, whether the seller rejected the top bid, and (for a
     * signed-in viewer) their highest bid, whether they lead, and whether they watch. Plus the two relations the card
     * reads, each loaded once for the whole page.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function withCardData(Builder $query, ?User $viewer): Builder
    {
        $this->withViewerBid($query, $viewer);

        if ($viewer !== null) {
            $query->selectRaw('exists (select 1 from watchlists where watchlists.auction_id = auctions.id and watchlists.user_id = ?) as viewer_watching', [$viewer->id]);
        }

        return $query->with(['images', 'winningBid']);
    }

    /**
     * The light version for table rows (no photos): bid count, rejected flag and the viewer's own bid facts.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function withViewerBid(Builder $query, ?User $viewer): Builder
    {
        $query->select('auctions.*')
            ->selectRaw('(select count(*) from bids where bids.auction_id = auctions.id) as bids_count')
            ->selectRaw(self::REJECTED.' as reserve_rejected');

        if ($viewer !== null) {
            $query
                ->selectRaw('(select max(amount) from bids where bids.auction_id = auctions.id and bids.bidder_id = ?) as viewer_top_bid', [$viewer->id])
                ->selectRaw('exists (select 1 from bids where bids.auction_id = auctions.id and bids.bidder_id = ? and bids.is_winning = ?) as viewer_leads', [$viewer->id, true]);
        }

        return $query;
    }

    /**
     * Lots that are no longer taking bids: sold, not sold, waiting on a seller, cancelled, or past their close and not settled yet.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function closed(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where(fn (Builder $q) => $q
            ->whereIn('auctions.status', ['ended', 'cancelled', 'pending_review'])
            ->orWhere(fn (Builder $a) => $a->where('auctions.status', 'active')->where('auctions.ends_at', '<=', $now)));
    }

    /**
     * Lots the user has placed at least one bid on.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function bidOnBy(Builder $query, User $user): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('bids')
            ->whereColumn('bids.auction_id', 'auctions.id')->where('bids.bidder_id', $user->id));
    }

    /**
     * Lots whose current winning bid belongs to the user.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function ledBy(Builder $query, User $user): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('bids')
            ->whereColumn('bids.auction_id', 'auctions.id')->where('bids.bidder_id', $user->id)->where('bids.is_winning', true));
    }

    /**
     * How many lots each tab would show for the current search and category: one grouped query.
     *
     * @return array<string, int> keys: all plus every state
     */
    public function counts(?string $search, ?int $category, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $window = $now->copy()->addMinutes($this->windowMinutes());

        $row = $this->base($search, $category)->toBase()->selectRaw(
            'count(*) as total,'
            ." sum(case when status = 'active' and starts_at <= ? and ends_at > ? then 1 else 0 end) as live,"
            ." sum(case when status = 'active' and starts_at <= ? and ends_at > ? and ends_at <= ? then 1 else 0 end) as ending,"
            ." sum(case when status = 'active' and starts_at > ? then 1 else 0 end) as scheduled,"
            ." sum(case when status = 'pending_review' then 1 else 0 end) as pending,"
            .' sum(case when status = ? and '.self::WINNING.' and not '.self::REJECTED.' then 1 else 0 end) as sold,'
            .' sum(case when (status = ? and (not '.self::WINNING.' or '.self::REJECTED.")) or (status = 'active' and ends_at <= ?) then 1 else 0 end) as unsold,"
            ." sum(case when status = 'cancelled' then 1 else 0 end) as cancelled",
            [$now, $now, $now, $now, $window, $now, 'ended', true, 'ended', true, $now],
        )->first();

        $out = ['all' => (int) ($row->total ?? 0)];
        foreach (array_keys(self::STATES) as $key) {
            $out[$key] = (int) ($row->{$key} ?? 0);
        }

        return $out;
    }

    private function windowMinutes(): int
    {
        return max(1, (int) config('auction.ending_soon_window_minutes', 60));
    }
}
