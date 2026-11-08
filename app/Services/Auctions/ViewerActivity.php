<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\ReserveNotMetProposal;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Models\Watchlist;
use App\Support\PageRange;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What one signed-in person is doing in the auction, read from their own rows: the lots they bid on, the ones they lead,
 * the sales they owe, what they watch and what they sell. A person is not a role: the same account can be buyer and seller,
 * so the dashboard and the buyer pages ask this class instead of checking a flag.
 *
 * Every method takes the user explicitly and filters on that user's id; nothing reads another person's rows. No reserve
 * amount is read here (the reserve decision panel is the seller's own component).
 */
final class ViewerActivity
{
    /** The closing window the dashboard calls "ending soon": the next 24 hours. */
    public const SOON_HOURS = 24;

    /**
     * Lists on a page that are not paged (open bids, sales to pay, reserve decisions) stop here, so one very busy account
     * cannot turn a page into hundreds of cards and thousands of query rows. Far above anything a person can act on.
     */
    public const SAFETY_CEILING = 120;

    public function __construct(private readonly LotBrowser $lots) {}

    /**
     * Whether this person has done anything at all: bid, watched, listed or been party to a sale. One query. A brand-new
     * account gets the welcome, not a page of empty sections.
     */
    public function hasAnyHistory(User $user): bool
    {
        $row = DB::selectOne(
            'select exists (select 1 from bids where bidder_id = ?) as bids,'
            .' exists (select 1 from watchlists where user_id = ?) as watching,'
            .' exists (select 1 from auctions where seller_id = ?) as selling,'
            .' exists (select 1 from auction_settlements where winner_id = ? or seller_id = ?) as sales',
            [$user->id, $user->id, $user->id, $user->id, $user->id],
        );

        return (bool) ($row->bids || $row->watching || $row->selling || $row->sales);
    }

    /**
     * Lots still taking bids that this person has bid on, with their highest bid and whether they lead. Outbid lots first
     * (they are the ones that need a move), then the lots they lead, soonest close first within each.
     *
     * @return Collection<int, Auction>
     */
    public function openBids(User $user, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        $query = $this->lots->withViewerBid($this->lots->bidOnBy(Auction::query(), $user), $user);
        $this->lots->inState($query, 'live', $now);

        return $query->orderByRaw('case when exists (select 1 from bids b where b.auction_id = auctions.id and b.bidder_id = ? and b.is_winning = ?) then 1 else 0 end', [$user->id, true])
            ->orderBy('auctions.ends_at')
            ->orderBy('auctions.id')
            ->limit(self::SAFETY_CEILING)
            ->get();
    }

    /**
     * Lots that ended below the reserve with this person's bid on top: nothing for them to do, the seller is deciding.
     *
     * @return Collection<int, Auction>
     */
    public function waitingOnSeller(User $user): Collection
    {
        $query = $this->lots->withViewerBid($this->lots->ledBy(Auction::query(), $user), $user);

        return $this->lots->inState($query, 'pending')->orderByDesc('auctions.ends_at')->limit(self::SAFETY_CEILING)->get();
    }

    /**
     * Lots this person bid on that are no longer taking bids (won, not won, not sold, cancelled), most recent close first.
     * Lots waiting on a seller's decision while this person leads are shown separately and left out here.
     *
     * @return LengthAwarePaginator<int, Auction>
     */
    public function endedBids(User $user, int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->lots->closed($this->lots->bidOnBy(Auction::query(), $user));
        $query->whereNot(fn ($q) => $q->where('auctions.status', 'pending_review')->whereExists(
            fn ($b) => $b->selectRaw('1')->from('bids')->whereColumn('bids.auction_id', 'auctions.id')
                ->where('bids.bidder_id', $user->id)->where('bids.is_winning', true)
        ));
        $this->lots->withViewerBid($query, $user)->with('winningBid');

        $page = $query->orderByDesc('auctions.ends_at')->orderByDesc('auctions.id')
            ->paginate($perPage, pageName: 'ended_page')->withQueryString();

        return $this->withMySettlements(PageRange::within($page), $user);
    }

    /**
     * Lots this person watches, open ones first by closing time, then the rest.
     *
     * @return LengthAwarePaginator<int, Auction>
     */
    public function watching(User $user, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->lots->withCardData($this->watchedBy(Auction::query(), $user)->browsable(), $user);

        return PageRange::within($this->lots->soonestFirst($query)->paginate($perPage)->withQueryString());
    }

    public function watchingCount(User $user): int
    {
        return Watchlist::withoutGlobalScopes()->where('user_id', $user->id)
            ->whereIn('auction_id', Auction::query()->browsable()->select('id'))->count();
    }

    /**
     * Lots this person won: ended with their bid on top and not rejected by the seller (a Buy Now purchase is the same
     * thing). The sale record, when it exists, is attached as `settlement` for the page to read.
     *
     * @return LengthAwarePaginator<int, Auction>
     */
    public function won(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->lots->ledBy(Auction::query()->where('auctions.status', 'ended'), $user)
            ->whereRaw('not exists (select 1 from reserve_not_met_proposals rp where rp.auction_id = auctions.id and rp.status = ?)', ['rejected']);
        $this->lots->withViewerBid($query, $user)->with('images');

        $page = PageRange::within($query->orderByDesc('auctions.ends_at')->orderByDesc('auctions.id')->paginate($perPage)->withQueryString());

        return $this->withMySettlements($page, $user);
    }

    /**
     * Attach the user's own sale record (as winner) to each lot of a page as the `settlement` relation: one query for the page.
     *
     * @param  LengthAwarePaginator<int, Auction>  $page
     * @return LengthAwarePaginator<int, Auction>
     */
    private function withMySettlements(LengthAwarePaginator $page, User $user): LengthAwarePaginator
    {
        $settlements = AuctionSettlement::query()
            ->where('winner_id', $user->id)
            ->whereIn('auction_id', $page->getCollection()->modelKeys())
            ->get()->keyBy('auction_id');

        $page->getCollection()->each(fn (Auction $a) => $a->setRelation('settlement', $settlements->get($a->id)));

        return $page;
    }

    /**
     * Sales this person must still pay (awaiting payment or overdue), overdue first, then by due date.
     *
     * @return Collection<int, AuctionSettlement>
     */
    public function salesToPay(User $user): Collection
    {
        return AuctionSettlement::query()
            ->where('winner_id', $user->id)
            ->whereIn('status', [AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAYMENT_OVERDUE])
            ->with('auction:id,title')
            ->orderByRaw("case when status = 'payment_overdue' then 0 else 1 end")
            ->orderBy('payment_due_at')
            ->orderBy('id')
            ->limit(self::SAFETY_CEILING)
            ->get();
    }

    /**
     * Sales this person is selling where the buyer is late. Only they can cancel, so it is theirs to act on.
     *
     * @return Collection<int, AuctionSettlement>
     */
    public function lateBuyers(User $user): Collection
    {
        return AuctionSettlement::query()
            ->where('seller_id', $user->id)
            ->where('status', AuctionSettlement::PAYMENT_OVERDUE)
            ->with('auction:id,title')
            ->orderBy('payment_due_at')
            ->limit(self::SAFETY_CEILING)
            ->get();
    }

    /**
     * @return Collection<int, ReserveNotMetProposal>
     */
    public function reserveDecisions(User $user): Collection
    {
        return ReserveNotMetProposal::query()
            ->where('status', 'pending')
            ->whereHas('auction', fn ($q) => $q->where('seller_id', $user->id))
            ->with(['auction', 'bid.bidder'])
            ->oldest()
            ->limit(self::SAFETY_CEILING)
            ->get();
    }

    /**
     * The seller's own numbers, from one grouped query, or null when they have never listed a lot.
     *
     * @return array{live: int, closing_soon: int, scheduled: int, pending: int, drafts: int, total: int}|null
     */
    public function sellerSummary(User $user, ?CarbonInterface $now = null): ?array
    {
        $now ??= now();

        $row = Auction::query()->toBase()->where('seller_id', $user->id)->selectRaw(
            'count(*) as total,'
            ." sum(case when status = 'active' and starts_at <= ? and ends_at > ? then 1 else 0 end) as live,"
            ." sum(case when status = 'active' and starts_at <= ? and ends_at > ? and ends_at <= ? then 1 else 0 end) as closing_soon,"
            ." sum(case when status = 'active' and starts_at > ? then 1 else 0 end) as scheduled,"
            ." sum(case when status = 'pending_review' then 1 else 0 end) as pending,"
            ." sum(case when status = 'draft' then 1 else 0 end) as drafts",
            [$now, $now, $now, $now, $now->copy()->addHours(self::SOON_HOURS), $now],
        )->first();

        if ((int) $row->total === 0) {
            return null;
        }

        return [
            'total' => (int) $row->total,
            'live' => (int) $row->live,
            'closing_soon' => (int) $row->closing_soon,
            'scheduled' => (int) $row->scheduled,
            'pending' => (int) $row->pending,
            'drafts' => (int) $row->drafts,
        ];
    }

    /**
     * The seller's lots that are open now, soonest close first.
     *
     * @return Collection<int, Auction>
     */
    public function sellingNow(User $user, int $limit = 6, ?CarbonInterface $now = null): Collection
    {
        $query = $this->lots->withViewerBid(Auction::query()->where('seller_id', $user->id), null);
        $this->lots->inState($query, 'live', $now);

        return $query->orderBy('auctions.ends_at')->orderBy('auctions.id')->limit($limit)->get();
    }

    /**
     * Watched lots that are open and close within the next day, soonest first.
     *
     * @return Collection<int, Auction>
     */
    public function watchedEndingSoon(User $user, int $limit = 4, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $query = $this->watchedBy(Auction::query(), $user)->where('auctions.ends_at', '<=', $now->copy()->addHours(self::SOON_HOURS));
        $this->lots->inState($query, 'live', $now);

        return $this->lots->withCardData($query, $user)->orderBy('auctions.ends_at')->orderBy('auctions.id')->limit($limit)->get();
    }

    /**
     * Open lots on the platform that close within the next day, soonest first: not the viewer's own, and not ones they already watch or have bid on.
     *
     * @return Collection<int, Auction>
     */
    public function endingSoonOnPlatform(User $user, int $limit = 4, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        // Lots they already see elsewhere on Home (watched, or bid on) are left out so a lot is never listed twice.
        $query = Auction::query()->where('auctions.seller_id', '!=', $user->id)
            ->where('auctions.ends_at', '<=', $now->copy()->addHours(self::SOON_HOURS))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('watchlists')->whereColumn('watchlists.auction_id', 'auctions.id')->where('watchlists.user_id', $user->id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bids')->whereColumn('bids.auction_id', 'auctions.id')->where('bids.bidder_id', $user->id));
        $this->lots->inState($query, 'live', $now);

        return $this->lots->withCardData($query, $user)->orderBy('auctions.ends_at')->orderBy('auctions.id')->limit($limit)->get();
    }

    /**
     * Payments that need a person (money that matched no sale). Only operators ever ask.
     */
    public function paymentsNeedingAPerson(): int
    {
        return SettlementPayment::query()->where('status', SettlementPayment::UNMATCHED)->count();
    }

    /**
     * The sum this person still owes across sales awaiting payment or overdue, in cents.
     */
    public function owedCents(User $user): int
    {
        return (int) AuctionSettlement::query()->where('winner_id', $user->id)
            ->whereIn('status', [AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAYMENT_OVERDUE])
            ->sum('amount_cents');
    }

    /**
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    private function watchedBy($query, User $user)
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('watchlists')
            ->whereColumn('watchlists.auction_id', 'auctions.id')->where('watchlists.user_id', $user->id));
    }
}
