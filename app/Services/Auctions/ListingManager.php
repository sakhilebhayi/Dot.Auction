<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every seller-side change to an auction: create, edit, publish, cancel,
 * delete.
 *
 * Like BidPlacer, each mutation runs in one transaction after locking the
 * auction row and re-reading it, so a seller holding a stale edit page
 * cannot change price, reserve or end time under a bid that landed after
 * the page loaded (BidPlacer locks the same row, so the two serialise).
 *
 * Status model: draft -> active (publish) -> ended | cancelled |
 * pending_review. There is no separate "scheduled" status: a published
 * auction whose starts_at is still in the future is 'active' but not yet
 * biddable (see Auction::isScheduled()).
 */
class ListingManager
{
    /** Fields that define the commercial terms of the sale. */
    public const TERM_FIELDS = [
        'title', 'category_id', 'starting_price', 'reserve_price',
        'buy_now_price', 'bid_increment', 'starts_at', 'ends_at',
    ];

    /**
     * @param  array<string, mixed>  $data  money as 2-decimal strings, dates as Carbon
     */
    public function create(User $seller, array $data): Auction
    {
        $startsAt = $this->openingTime($data['starts_at'] ?? null);

        $this->assertSound($data, $startsAt);

        return Auction::create([
            'seller_id' => $seller->getKey(),
            'category_id' => $data['category_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'starting_price' => $data['starting_price'],
            'current_price' => $data['starting_price'],
            'reserve_price' => $data['reserve_price'] ?? null,
            'buy_now_price' => $data['buy_now_price'] ?? null,
            'bid_increment' => $data['bid_increment'],
            'status' => 'draft',
            'starts_at' => $startsAt,
            'ends_at' => $data['ends_at'],
        ]);
    }

    /**
     * Before the first bid every field can change. After it, only the
     * description: any attempt to send another field is refused, never
     * silently dropped, so a stale form cannot look like it saved.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ListingRejected
     */
    public function update(Auction|int $auction, array $data): Auction
    {
        return DB::transaction(function () use ($auction, $data) {
            $locked = $this->lock($auction);

            if (! in_array($locked->status, ['draft', 'active'], true)) {
                throw new ListingRejected('This auction can no longer be edited.');
            }

            if ($locked->hasBids()) {
                if (array_intersect(array_keys($data), self::TERM_FIELDS) !== []) {
                    throw new ListingRejected('A bid has been placed since you opened this page, so price, reserve, closing time and the other terms are now locked. Only the description can change.');
                }

                $locked->update(['description' => $data['description'] ?? $locked->description]);

                return $locked;
            }

            $started = $locked->status === 'active' && ! $locked->starts_at->isFuture();
            $startsAt = match (true) {
                $started => $locked->starts_at,
                array_key_exists('starts_at', $data) => $this->openingTime($data['starts_at']),
                default => $this->openingTime($locked->starts_at),
            };

            $terms = array_merge($this->currentTerms($locked), $data);
            $this->assertSound($terms, $startsAt);

            $locked->update([
                'category_id' => $terms['category_id'],
                'title' => $terms['title'],
                'description' => $terms['description'] ?? null,
                'starting_price' => $terms['starting_price'],
                'current_price' => $terms['starting_price'],
                'reserve_price' => $terms['reserve_price'] ?? null,
                'buy_now_price' => $terms['buy_now_price'] ?? null,
                'bid_increment' => $terms['bid_increment'],
                'starts_at' => $startsAt,
                'ends_at' => $terms['ends_at'],
            ]);

            return $locked;
        });
    }

    /**
     * draft -> active. An opening time that has already passed becomes
     * "now"; a future one is kept, which makes the auction scheduled.
     *
     * @throws ListingRejected
     */
    public function publish(Auction|int $auction): Auction
    {
        return DB::transaction(function () use ($auction) {
            $locked = $this->lock($auction);

            if ($locked->status !== 'draft') {
                throw new ListingRejected('Only a draft can be published.');
            }

            $startsAt = $locked->starts_at->isFuture() ? $locked->starts_at : now();

            $this->assertSound($this->currentTerms($locked), $startsAt);

            $locked->update([
                'status' => 'active',
                'starts_at' => $startsAt,
                'current_price' => $locked->starting_price,
            ]);

            return $locked;
        });
    }

    /**
     * @throws ListingRejected
     */
    public function cancel(Auction|int $auction): Auction
    {
        return DB::transaction(function () use ($auction) {
            $locked = $this->lock($auction);

            if ($locked->status === 'draft') {
                throw new ListingRejected('A draft is deleted, not cancelled.');
            }

            if ($locked->status !== 'active') {
                throw new ListingRejected('Only a live or scheduled auction can be cancelled.');
            }

            if ($locked->hasBids()) {
                throw new ListingRejected('An auction that has received bids cannot be cancelled.');
            }

            $locked->update(['status' => 'cancelled']);

            return $locked;
        });
    }

    /**
     * @throws ListingRejected
     */
    public function delete(Auction|int $auction): void
    {
        DB::transaction(function () use ($auction) {
            $locked = $this->lock($auction);

            if ($locked->status !== 'draft') {
                throw new ListingRejected('Only a draft can be deleted. A live auction without bids can be cancelled instead.');
            }

            $locked->delete();
        });
    }

    private function lock(Auction|int $auction): Auction
    {
        $id = $auction instanceof Auction ? $auction->getKey() : $auction;

        return Auction::query()->lockForUpdate()->find($id)
            ?? throw new ListingRejected('This auction no longer exists.');
    }

    /**
     * A blank or already-passed opening time means "as soon as it is
     * published", which is stored as now.
     */
    private function openingTime(mixed $startsAt): CarbonInterface
    {
        return $startsAt instanceof CarbonInterface && $startsAt->isFuture() ? $startsAt : now();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentTerms(Auction $auction): array
    {
        return [
            'title' => $auction->title,
            'description' => $auction->description,
            'category_id' => $auction->category_id,
            'starting_price' => $auction->starting_price,
            'reserve_price' => $auction->reserve_price,
            'buy_now_price' => $auction->buy_now_price,
            'bid_increment' => $auction->bid_increment,
            'ends_at' => $auction->ends_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $terms
     *
     * @throws ListingRejected
     */
    private function assertSound(array $terms, CarbonInterface $startsAt): void
    {
        $problems = ListingTerms::problems(
            Auction::toCents($terms['starting_price']),
            isset($terms['reserve_price']) ? Auction::toCents($terms['reserve_price']) : null,
            isset($terms['buy_now_price']) ? Auction::toCents($terms['buy_now_price']) : null,
            Auction::toCents($terms['bid_increment']),
            $startsAt,
            $terms['ends_at'],
        );

        if ($problems !== []) {
            throw new ListingRejected(reset($problems));
        }
    }
}
