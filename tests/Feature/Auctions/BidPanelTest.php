<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class BidPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeAuction(array $overrides = []): Auction
    {
        $seller = User::factory()->create();

        return Auction::create(array_merge([
            'seller_id' => $seller->id,
            'title' => 'Signed Guitar',
            'starting_price' => 200,
            'current_price' => 200,
            'bid_increment' => 20,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ], $overrides));
    }

    public function test_a_buyer_can_place_a_valid_bid(): void
    {
        Event::fake();
        $auction = $this->makeAuction();
        $buyer = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($buyer)
            ->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 220)
            ->call('placeBid')
            ->assertSet('error', null)
            ->assertSet('success', 'Bid placed successfully!');

        $this->assertDatabaseHas('bids', [
            'auction_id' => $auction->id,
            'bidder_id' => $buyer->id,
            'amount' => 220,
            'is_winning' => true,
        ]);
        $this->assertEquals(220, $auction->fresh()->current_price);
    }

    public function test_a_bid_below_the_minimum_is_rejected(): void
    {
        $auction = $this->makeAuction();
        $buyer = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($buyer)
            ->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 205)
            ->call('placeBid')
            ->assertSet('success', null);

        $this->assertDatabaseCount('bids', 0);
        $this->assertEquals(200, $auction->fresh()->current_price);
    }

    public function test_a_seller_cannot_bid_on_their_own_auction(): void
    {
        $auction = $this->makeAuction();
        $seller = $auction->seller;

        Livewire::actingAs($seller)
            ->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 220)
            ->call('placeBid')
            ->assertSet('error', 'You cannot bid on your own auction.');

        $this->assertDatabaseCount('bids', 0);
    }

    public function test_the_previous_leading_bidder_receives_an_outbid_notification(): void
    {
        $auction = $this->makeAuction();
        $firstBuyer = User::factory()->withPersonalTeam()->create();
        $newBuyer = User::factory()->withPersonalTeam()->create();

        Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $firstBuyer->id,
            'amount' => 220,
            'is_winning' => true,
        ]);
        $auction->update(['current_price' => 220]);

        Livewire::actingAs($newBuyer)
            ->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 240)
            ->call('placeBid')
            ->assertSet('success', 'Bid placed successfully!');

        $this->assertEquals(1, $firstBuyer->fresh()->unreadNotifications()->count());
        $this->assertEquals(0, $newBuyer->fresh()->unreadNotifications()->count());
    }
}
