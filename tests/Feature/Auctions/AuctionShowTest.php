<?php

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_an_auction(): void
    {
        $seller = User::factory()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Antique Clock',
            'starting_price' => 50,
            'current_price' => 50,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $this->get(route('auctions.show', $auction))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_an_active_auction(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->withPersonalTeam()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Antique Clock',
            'starting_price' => 50,
            'current_price' => 50,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($buyer)
            ->get(route('auctions.show', $auction))
            ->assertOk()
            ->assertViewIs('auctions.show')
            ->assertSee('Antique Clock')
            ->assertSeeLivewire('auctions.bid-panel');
    }

    public function test_draft_auction_is_hidden_from_non_sellers(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->withPersonalTeam()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Unlisted Lot',
            'starting_price' => 50,
            'current_price' => 50,
            'bid_increment' => 5,
            'status' => 'draft',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($buyer)
            ->get(route('auctions.show', $auction))
            ->assertForbidden();
    }

    public function test_reserve_price_amount_is_never_rendered_to_a_non_seller(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->withPersonalTeam()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Reserved Lot',
            'starting_price' => 50,
            'reserve_price' => 987.65,
            'current_price' => 50,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($buyer)->get(route('auctions.show', $auction));

        $response->assertOk();
        $response->assertDontSee('987.65');
        $response->assertSee('Reserve not yet met');
    }
}
