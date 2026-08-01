<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\WatchlistButton;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_add_and_remove_an_auction_from_their_watchlist(): void
    {
        $seller  = User::factory()->create();
        $buyer   = User::factory()->withPersonalTeam()->create();
        $auction = Auction::create([
            'seller_id'      => $seller->id,
            'title'          => 'Rare Stamp Collection',
            'starting_price' => 30,
            'current_price'  => 30,
            'bid_increment'  => 5,
            'status'         => 'active',
            'starts_at'      => now()->subHour(),
            'ends_at'        => now()->addDay(),
        ]);

        $component = Livewire::actingAs($buyer)
            ->test(WatchlistButton::class, ['auction' => $auction])
            ->assertSet('watching', false);

        $component->call('toggle')->assertSet('watching', true);
        $this->assertDatabaseHas('watchlists', [
            'user_id'    => $buyer->id,
            'auction_id' => $auction->id,
        ]);

        $component->call('toggle')->assertSet('watching', false);
        $this->assertDatabaseMissing('watchlists', [
            'user_id'    => $buyer->id,
            'auction_id' => $auction->id,
        ]);
    }
}
