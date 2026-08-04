<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\WatchlistButton;
use App\Models\Auction;
use App\Models\User;
use App\Models\Watchlist;
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

    /**
     * Proves HasUserScope itself is load-bearing, independent of any
     * Policy or controller check: there is no WatchlistPolicy at all in
     * this codebase — every call site (WatchlistButton, the dashboard
     * route) just happens to remember to filter by the current user.
     * Querying Watchlist directly as a different user, with no
     * authorization check anywhere in the path, still cannot see the
     * row. This is the property that makes the scope "defense in depth"
     * rather than decorative — it holds even if a future call site
     * forgets to filter by user id entirely.
     */
    public function test_scope_alone_blocks_cross_user_access_even_without_a_policy_check(): void
    {
        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $seller  = User::factory()->create();
        $auction = Auction::create([
            'seller_id'      => $seller->id,
            'title'          => 'Cross-User Watch Test Lot',
            'starting_price' => 20,
            'current_price'  => 20,
            'bid_increment'  => 5,
            'status'         => 'active',
            'starts_at'      => now()->subHour(),
            'ends_at'        => now()->addDay(),
        ]);

        $watchlistEntry = Watchlist::create(['user_id' => $owner->id, 'auction_id' => $auction->id]);

        $this->actingAs($other);

        $this->assertSame(0, Watchlist::query()->count());
        $this->assertFalse(Watchlist::where('auction_id', $auction->id)->exists());

        $this->actingAs($owner);

        $this->assertSame(1, Watchlist::query()->count());
        $this->assertTrue(Watchlist::where('auction_id', $auction->id)->exists());
        $this->assertNotNull($watchlistEntry);
    }
}
