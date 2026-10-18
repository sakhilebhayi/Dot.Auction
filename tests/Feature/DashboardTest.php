<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_with_no_team_is_redirected_to_team_creation(): void
    {
        // A plain factory user (no withPersonalTeam()) owns zero teams, so
        // HasTeams::currentTeam()'s null->personalTeam() fallback also
        // resolves to null. Every other test in this file uses
        // withPersonalTeam(); this one reproduces the user who reaches
        // /dashboard without one (e.g. provisioned outside
        // App\Actions\Fortify\CreateNewUser) and asserts we redirect
        // instead of the navigation partial crashing on a null currentTeam.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('teams.create'));
    }

    public function test_authenticated_user_can_view_the_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Auction Dashboard');
    }

    public function test_dashboard_shows_watchlist_count(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $seller = User::factory()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Watched Lot',
            'starting_price' => 10,
            'current_price' => 10,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        Watchlist::create(['user_id' => $user->id, 'auction_id' => $auction->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Watchlist');
        $response->assertSeeText('1', false);
    }

    public function test_dashboard_ending_soon_widget_lists_auctions_closing_within_24_hours(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $seller = User::factory()->create();

        Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Closing Soon Lot',
            'starting_price' => 10,
            'current_price' => 10,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
        ]);

        Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Far Future Lot',
            'starting_price' => 10,
            'current_price' => 10,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(10),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Closing Soon Lot');
        $response->assertDontSee('Far Future Lot');
    }

    public function test_dashboard_has_no_dead_placeholder_links(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('href="#"', $html);
        // Seller listing management shipped: the control is a real link now,
        // not an aria-disabled stand-in.
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('href="'.route('seller.auctions.create').'"', $html);
    }

    public function test_dashboard_uses_the_same_currency_symbol_as_the_bid_panel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Auction::create([
            'seller_id' => $user->id,
            'title' => 'Priced Lot',
            'starting_price' => 1234.5,
            'current_price' => 1500,
            'buy_now_price' => 2000,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('R1,234.50', false)
            ->assertSee('R1,500.00', false)
            ->assertSee('R2,000.00', false);
        $this->assertStringNotContainsString('$1,', $response->getContent());
    }
}
