<?php

namespace App\Support;

use App\Models\Auction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The states a seller filters their own lots by, defined once so the tab counts and the rows behind a tab can never
 * disagree. Read-only: it builds queries and changes nothing.
 *
 * The state names are the seller's words for what Display::lotState() derives per lot:
 *   pending    ended below the reserve, waiting for the seller's decision
 *   live       published, open for bids now
 *   ending     live and inside the closing-soon window (a subset of live)
 *   scheduled  published, not open yet
 *   draft      private, never published
 *   ended      closed (sold, not sold, or closed and not yet settled)
 *   cancelled  cancelled by the seller
 */
final class SellerLots
{
    /** @var array<string, string> state => tab label, in tab order */
    public const STATES = [
        'pending' => 'Needs your decision',
        'live' => 'Live',
        'ending' => 'Closing soon',
        'scheduled' => 'Scheduled',
        'draft' => 'Drafts',
        'ended' => 'Ended',
        'cancelled' => 'Cancelled',
    ];

    public static function isState(?string $state): bool
    {
        return $state !== null && array_key_exists($state, self::STATES);
    }

    /**
     * SQL and bindings for one state, valid on SQLite and PostgreSQL (bound strings compare with timestamp columns on both).
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private static function predicate(string $state, CarbonInterface $now, int $endingSoonMinutes): array
    {
        $at = $now->copy()->utc()->format('Y-m-d H:i:s');
        $soon = $now->copy()->utc()->addMinutes($endingSoonMinutes)->format('Y-m-d H:i:s');

        return match ($state) {
            'draft' => ["status = 'draft'", []],
            'cancelled' => ["status = 'cancelled'", []],
            'pending' => ["status = 'pending_review'", []],
            'scheduled' => ["(status = 'active' and starts_at > ?)", [$at]],
            'live' => ["(status = 'active' and starts_at <= ? and ends_at > ?)", [$at, $at]],
            'ending' => ["(status = 'active' and starts_at <= ? and ends_at > ? and ends_at <= ?)", [$at, $at, $soon]],
            'ended' => ["(status = 'ended' or (status = 'active' and ends_at <= ?))", [$at]],
            default => ['1 = 1', []],
        };
    }

    /**
     * Narrow a seller's lots to one state and give them the order that suits it. An unknown state changes nothing
     * but the order.
     *
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public static function apply(Builder $query, ?string $state, CarbonInterface $now, int $endingSoonMinutes): Builder
    {
        if (! self::isState($state)) {
            // All: what needs the seller first, then drafts, then everything open, newest first.
            return $query
                ->orderByRaw("CASE status WHEN 'pending_review' THEN 0 WHEN 'draft' THEN 1 WHEN 'active' THEN 2 ELSE 3 END")
                ->orderByDesc('id');
        }

        [$sql, $bindings] = self::predicate($state, $now, $endingSoonMinutes);
        $query->whereRaw($sql, $bindings);

        return match ($state) {
            'live', 'ending', 'pending' => $query->orderBy('ends_at')->orderBy('id'),
            'scheduled' => $query->orderBy('starts_at')->orderBy('id'),
            'ended' => $query->orderByDesc('ends_at')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }

    /**
     * How many lots the seller has in each state, and in total, in one query.
     *
     * @return array<string, int> keys: all and every state in self::STATES
     */
    public static function counts(int|string $sellerId, CarbonInterface $now, int $endingSoonMinutes): array
    {
        $selects = ['count(*) as all_lots'];
        $bindings = [];

        foreach (array_keys(self::STATES) as $state) {
            [$sql, $own] = self::predicate($state, $now, $endingSoonMinutes);
            $selects[] = "coalesce(sum(case when {$sql} then 1 else 0 end), 0) as {$state}_lots";
            array_push($bindings, ...$own);
        }

        $row = Auction::query()
            ->where('seller_id', $sellerId)
            ->selectRaw(implode(', ', $selects), $bindings)
            ->toBase()
            ->first();

        $counts = ['all' => (int) ($row->all_lots ?? 0)];
        foreach (array_keys(self::STATES) as $state) {
            $counts[$state] = (int) ($row->{$state.'_lots'} ?? 0);
        }

        return $counts;
    }
}
