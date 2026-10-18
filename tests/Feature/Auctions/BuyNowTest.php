<?php

namespace Tests\Feature\Auctions;

use App\Events\AuctionSettled;
use App\Events\BidPlaced;
use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class BuyNowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function auction(array $overrides = []): Auction
    {
        return Auction::factory()->create(array_merge(['buy_now_price' => '500.00'], $overrides));
    }

    private function buyer(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function buy(Auction|int $auction, User $buyer): Bid
    {
        return app(BuyNowPurchaser::class)->buy($auction, $buyer);
    }

    public function test_buying_now_ends_the_auction_and_makes_the_buyer_the_winner(): void
    {
        Notification::fake();
        Event::fake([AuctionSettled::class, BidPlaced::class]);
        $auction = $this->auction();
        $buyer = $this->buyer();

        $bid = $this->buy($auction, $buyer);

        $fresh = $auction->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame(50000, Auction::toCents($fresh->current_price));
        $this->assertTrue($fresh->ends_at->lessThanOrEqualTo(now()));
        $this->assertSame($buyer->id, $bid->bidder_id);
        $this->assertSame(50000, Auction::toCents($bid->amount));
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
        $this->assertSame($bid->id, $fresh->winningBid->id);

        Notification::assertSentToTimes($buyer, AuctionWonNotification::class, 1);
        Event::assertDispatchedTimes(AuctionSettled::class, 1);
        Event::assertDispatched(AuctionSettled::class, fn ($e) => $e->winningBid?->id === $bid->id);
        Event::assertDispatched(BidPlaced::class);
    }

    public function test_the_previous_leader_is_told_they_were_outbid(): void
    {
        Notification::fake();
        $auction = $this->auction();
        $leader = $this->buyer();
        app(BidPlacer::class)->place($auction, $leader, '220.00');

        $this->buy($auction, $this->buyer());

        Notification::assertSentTo($leader, OutbidNotification::class);
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
    }

    public function test_buy_now_is_unavailable_when_none_is_set(): void
    {
        $auction = $this->auction(['buy_now_price' => null]);

        $this->expectException(BidRejected::class);
        $this->buy($auction, $this->buyer());
    }

    public function test_buy_now_is_blocked_once_the_price_has_reached_it(): void
    {
        $auction = $this->auction();
        $stale = Auction::find($auction->id);
        app(BidPlacer::class)->place($auction, $this->buyer(), '500.00');

        try {
            $this->buy($stale, $this->buyer());
            $this->fail('a stale page must not buy below the live price');
        } catch (BidRejected $e) {
            $this->assertStringContainsString('Buy Now', $e->getMessage());
        }

        $this->assertSame('active', $auction->fresh()->status);
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
    }

    public function test_buy_now_is_blocked_once_bids_exceed_it(): void
    {
        $auction = $this->auction(['current_price' => '640.00']);

        $this->expectException(BidRejected::class);
        $this->buy($auction, $this->buyer());
    }

    public function test_buy_now_is_still_available_below_the_price(): void
    {
        $auction = $this->auction();
        app(BidPlacer::class)->place($auction, $this->buyer(), '480.00');

        $bid = $this->buy($auction, $this->buyer());

        $this->assertSame(50000, Auction::toCents($bid->amount));
        $this->assertSame('ended', $auction->fresh()->status);
    }

    public function test_the_seller_cannot_buy_their_own_auction(): void
    {
        $auction = $this->auction();

        $this->expectException(BidRejected::class);
        $this->buy($auction, $auction->seller);
    }

    public function test_a_reserve_above_the_buy_now_price_blocks_the_purchase(): void
    {
        $auction = $this->auction(['reserve_price' => '600.00']);

        $this->expectException(BidRejected::class);
        try {
            $this->buy($auction, $this->buyer());
        } finally {
            $this->assertSame('active', $auction->fresh()->status);
        }
    }

    public function test_a_reserve_at_or_below_the_buy_now_price_still_settles_as_a_sale(): void
    {
        $auction = $this->auction(['reserve_price' => '500.00']);

        $this->buy($auction, $this->buyer());

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertDatabaseCount('reserve_not_met_proposals', 0);
    }

    public function test_buy_now_is_rejected_outside_the_live_window(): void
    {
        foreach ([
            $this->auction(['status' => 'draft']),
            $this->auction(['status' => 'cancelled']),
            $this->auction(['status' => 'ended']),
            $this->auction(['starts_at' => now()->addHour(), 'ends_at' => now()->addDays(2)]),
            $this->auction(['ends_at' => now()->subMinute()]),
        ] as $auction) {
            try {
                $this->buy($auction, $this->buyer());
                $this->fail("auction {$auction->id} ({$auction->status}) must not be buyable");
            } catch (BidRejected) {
                $this->assertSame(0, Bid::where('auction_id', $auction->id)->count());
            }
        }
    }

    public function test_a_second_purchase_is_rejected_and_settlement_stays_idempotent(): void
    {
        Notification::fake();
        Event::fake([AuctionSettled::class]);
        $auction = $this->auction();
        $first = $this->buyer();
        $this->buy($auction, $first);

        try {
            $this->buy($auction, $this->buyer());
            $this->fail('second purchase must be rejected');
        } catch (BidRejected) {
        }

        $this->assertFalse(app(AuctionSettler::class)->settle($auction), 'the settler must treat it as already settled');
        $this->artisan('auction:settle-ended')->assertSuccessful();

        Notification::assertSentToTimes($first, AuctionWonNotification::class, 1);
        Event::assertDispatchedTimes(AuctionSettled::class, 1);
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
    }

    public function test_normal_bidding_is_closed_after_a_purchase(): void
    {
        $auction = $this->auction();
        $this->buy($auction, $this->buyer());

        $this->expectException(BidRejected::class);
        app(BidPlacer::class)->place($auction, $this->buyer(), '900.00');
    }

    public function test_the_bid_panel_offers_buy_now_and_completes_the_purchase(): void
    {
        Notification::fake();
        $auction = $this->auction();
        $buyer = $this->buyer();

        Livewire::actingAs($buyer)
            ->test(BidPanel::class, ['auction' => $auction])
            ->assertSee('Buy now for R500.00')
            ->call('buyNow')
            ->assertSee('You bought this auction')
            ->assertDontSee('Buy now for');

        $this->assertSame('ended', $auction->fresh()->status);
        Notification::assertSentTo($buyer, AuctionWonNotification::class);
    }

    public function test_the_bid_panel_hides_buy_now_from_the_seller_and_after_it_is_exceeded(): void
    {
        $auction = $this->auction();

        Livewire::actingAs($auction->seller)
            ->test(BidPanel::class, ['auction' => $auction])
            ->assertDontSee('Buy now for');

        $exceeded = $this->auction(['current_price' => '500.00']);
        Livewire::actingAs($this->buyer())
            ->test(BidPanel::class, ['auction' => $exceeded])
            ->assertDontSee('Buy now for');
    }

    public function test_the_bid_panel_reports_a_blocked_purchase_honestly(): void
    {
        $auction = $this->auction();
        $buyer = $this->buyer();
        $component = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction]);

        app(BidPlacer::class)->place($auction, $this->buyer(), '520.00'); // someone passes the Buy Now price

        $component->call('buyNow')->assertSee('Buy Now price');
        $this->assertSame('active', $auction->fresh()->status);
    }
}
