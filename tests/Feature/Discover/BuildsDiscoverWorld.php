<?php

namespace Tests\Feature\Discover;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;

/** Small builders for the discover tests: people, lots, bids and sales written the way the services leave them. */
trait BuildsDiscoverWorld
{
    protected function person(string $name = 'Test Person'): User
    {
        return User::factory()->withPersonalTeam()->create(['name' => $name]);
    }

    /** A live lot (opened an hour ago, closes tomorrow) unless overridden. */
    protected function lot(array $overrides = [], ?User $seller = null): Auction
    {
        return Auction::factory()->create(array_merge(['seller_id' => ($seller ?? $this->person('Seller '.uniqid()))->id], $overrides));
    }

    /** What BidPlacer leaves behind: the previous leader is demoted, the new bid is the leader, the price moves. */
    protected function bid(Auction $lot, User $bidder, string $amount): Bid
    {
        Bid::query()->where('auction_id', $lot->id)->where('is_winning', true)->update(['is_winning' => false]);
        $bid = Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $bidder->id, 'amount' => $amount, 'is_winning' => true]);
        $lot->forceFill(['current_price' => $amount])->save();

        return $bid;
    }

    /** A lot that ended: the stored state the settler leaves ('ended' with its winning bid still marked). */
    protected function endedLot(User $winner, string $amount = '400.00', array $overrides = []): Auction
    {
        $lot = $this->lot(array_merge(['status' => 'ended', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()], $overrides));
        $this->bid($lot, $winner, $amount);

        return $lot->fresh();
    }

    protected function sale(Auction $lot, User $winner, string $status = 'awaiting_payment', ?\DateTimeInterface $due = null): AuctionSettlement
    {
        $bid = Bid::query()->where('auction_id', $lot->id)->where('is_winning', true)->firstOrFail();

        return AuctionSettlement::unguarded(fn () => AuctionSettlement::create([
            'auction_id' => $lot->id, 'winning_bid_id' => $bid->id, 'seller_id' => $lot->seller_id, 'winner_id' => $winner->id,
            'amount_cents' => (int) round(((float) $lot->current_price) * 100), 'currency' => 'ZAR', 'status' => $status,
            'payment_due_at' => $due ?? now()->addDays(2),
        ]));
    }

    /** A lot that ended below its reserve and waits for the seller (status pending_review, a pending proposal). */
    protected function awaitingDecision(User $seller, User $topBidder, string $price = '300.00', string $reserve = '500.00'): ReserveNotMetProposal
    {
        $lot = $this->lot([
            'status' => 'pending_review', 'reserve_price' => $reserve, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(),
        ], $seller);
        $bid = $this->bid($lot, $topBidder, $price);

        return ReserveNotMetProposal::forceCreate(['auction_id' => $lot->id, 'bid_id' => $bid->id, 'reserve_price' => $reserve, 'status' => 'pending']);
    }

    protected function category(string $name): AuctionCategory
    {
        return AuctionCategory::create(['name' => $name, 'slug' => str($name)->slug()->append('-'.uniqid())->toString()]);
    }
}
