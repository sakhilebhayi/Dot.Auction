<?php

namespace App\Services\Analytics;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The numbers Dot.Auction publishes to Dot.Analytics: pure read-only aggregate queries, scoped
 * explicitly to the sellers of ONE company. Only the keys of Dot.Analytics' metrics contract for
 * the `auction` platform are ever produced: active, ended_7d, gmv_7d_cents, bids_7d, and
 * `currency` for the money key. No name, title, address or row ever leaves here.
 *
 * Who a company is: Auctions belong to their SELLER (a user), and Dot.Analytics wants a company,
 * so a company is the InfoDot team (infodot_team_uuid) the seller currently belongs to (see
 * CompanyDirectory). A seller with no linked team is in no company and is never published.
 *
 * Windows are UTC. taken_at is the start of a UTC day and every "7d" metric covers the seven
 * whole UTC days before it, [taken_at - 7d, taken_at): the same day always yields the same 7 day
 * numbers however late it runs. The bounds are converted to the app timezone before they reach a
 * query because timestamps are stored in it. The point-in-time metric (active) is read when the
 * snapshot is collected.
 *
 *   active        status 'active' and inside its open window right now (Auction::isActive)
 *   ended_7d      status 'ended' with its closing time (ends_at, after any anti-snipe
 *                 extension) in the window. Not 'pending_review' (reserve not met, awaiting the
 *                 seller), not cancelled, not drafts
 *   gmv_7d_cents  sum of auction_settlements.amount_cents (the winning amount) recorded in the
 *                 window, in the configured currency only; other currencies are never mixed in
 *   bids_7d       every bid row placed in the window on the company's sellers' auctions
 *                 (automatic proxy bids are bid rows too)
 */
class MetricsCollector
{
    /** @var list<string> */
    public const KEYS = ['active', 'ended_7d', 'gmv_7d_cents', 'bids_7d', 'currency'];

    public function __construct(private readonly CompanyDirectory $directory) {}

    /**
     * One snapshot per company that has sellers with published (non-draft) auctions.
     *
     * @return array<string, array<string, int|string>> company uuid => metrics
     */
    public function snapshots(CarbonInterface $takenAt, ?string $onlyCompany = null): array
    {
        $sellerIds = Auction::query()->where('status', '!=', 'draft')->distinct()->pluck('seller_id')->all();
        $out = [];

        foreach ($this->directory->group($sellerIds) as $uuid => $members) {
            if ($onlyCompany !== null && strtolower($uuid) !== strtolower($onlyCompany)) {
                continue;
            }

            $metrics = $this->collect($members, $takenAt);

            if ($metrics !== []) {
                $out[$uuid] = $metrics;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $sellerIds  the users of ONE company (never empty meaning "everyone")
     * @return array<string, int|string>
     */
    public function collect(array $sellerIds, CarbonInterface $takenAt, ?CarbonInterface $now = null): array
    {
        if ($sellerIds === []) {
            return [];
        }

        $to = $this->local(Carbon::instance($takenAt)->utc()->startOfDay());
        $from = $to->copy()->subDays(7);
        $now = $this->local($now ?? Carbon::now());

        $metrics = [
            'active' => Auction::query()
                ->whereIn('seller_id', $sellerIds)
                ->where('status', 'active')
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->count(),
            'ended_7d' => Auction::query()
                ->whereIn('seller_id', $sellerIds)
                ->where('status', 'ended')
                ->where('ends_at', '>=', $from)
                ->where('ends_at', '<', $to)
                ->count(),
        ];

        $currency = strtoupper(trim((string) config('auction.currency_code')));

        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $metrics['gmv_7d_cents'] = (int) AuctionSettlement::query()
                ->whereIn('seller_id', $sellerIds)
                ->whereRaw('UPPER(currency) = ?', [$currency])
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->sum('amount_cents');
        }

        $metrics['bids_7d'] = Bid::query()
            ->whereIn('auction_id', Auction::query()->whereIn('seller_id', $sellerIds)->select('id'))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        if (isset($metrics['gmv_7d_cents'])) {
            $metrics['currency'] = $currency;
        }

        return $metrics;
    }

    /** A moment, expressed in the timezone the database stores timestamps in. */
    private function local(CarbonInterface $moment): Carbon
    {
        return Carbon::instance($moment)->timezone((string) config('app.timezone'));
    }
}
