<?php

namespace Tests\Feature\Console;

use App\Events\AuctionSettled;
use App\Events\ReserveNotMet;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SettleEndedAuctionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeEndedAuction(array $overrides = []): Auction
    {
        $seller = User::factory()->create();

        return Auction::create(array_merge([
            'seller_id' => $seller->id,
            'title' => 'Antique Clock',
            'starting_price' => 100,
            'current_price' => 100,
            'bid_increment' => 10,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_an_auction_with_no_bids_ends_unsold(): void
    {
        Event::fake([AuctionSettled::class]);
        $auction = $this->makeEndedAuction();

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertDatabaseCount('reserve_not_met_proposals', 0);
        Event::assertDispatched(AuctionSettled::class, fn ($e) => $e->auction->is($auction) && $e->winningBid === null);
    }

    public function test_an_auction_with_reserve_met_settles_and_notifies_the_winner(): void
    {
        Notification::fake();
        Event::fake([AuctionSettled::class]);
        $auction = $this->makeEndedAuction(['reserve_price' => 150, 'current_price' => 200]);
        $bidder = User::factory()->create();
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 200,
            'is_winning' => true,
        ]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        Notification::assertSentTo($bidder, AuctionWonNotification::class);
        Event::assertDispatched(AuctionSettled::class, fn ($e) => $e->auction->is($auction) && $e->winningBid->is($bid));
    }

    public function test_an_auction_with_no_reserve_settles_like_reserve_met(): void
    {
        Notification::fake();
        $auction = $this->makeEndedAuction(['current_price' => 120]);
        $bidder = User::factory()->create();
        Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 120,
            'is_winning' => true,
        ]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        Notification::assertSentTo($bidder, AuctionWonNotification::class);
    }

    public function test_an_auction_with_reserve_not_met_creates_a_proposal_and_does_not_notify(): void
    {
        Notification::fake();
        Event::fake([ReserveNotMet::class]);
        $auction = $this->makeEndedAuction(['reserve_price' => 500, 'current_price' => 300]);
        $bidder = User::factory()->create();
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 300,
            'is_winning' => true,
        ]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('pending_review', $auction->fresh()->status);
        $this->assertDatabaseHas('reserve_not_met_proposals', [
            'auction_id' => $auction->id,
            'bid_id' => $bid->id,
            'reserve_price' => 500,
            'status' => 'pending',
        ]);
        Notification::assertNothingSent();
        Event::assertDispatched(ReserveNotMet::class, fn ($e) => $e->proposal->auction_id === $auction->id);
    }

    public function test_an_auction_not_yet_past_ends_at_is_left_untouched(): void
    {
        $auction = $this->makeEndedAuction(['ends_at' => now()->addDay()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('active', $auction->fresh()->status);
    }
}
