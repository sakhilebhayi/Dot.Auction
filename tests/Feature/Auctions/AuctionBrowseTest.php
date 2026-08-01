<?php

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionBrowseTest extends TestCase
{
    use RefreshDatabase;

    private function makeAuction(array $overrides = []): Auction
    {
        $seller = User::factory()->create();

        return Auction::create(array_merge([
            'seller_id'      => $seller->id,
            'title'          => 'Vintage Camera',
            'description'    => 'A well kept vintage camera.',
            'starting_price' => 100,
            'current_price'  => 100,
            'bid_increment'  => 10,
            'status'         => 'active',
            'starts_at'      => now()->subHour(),
            'ends_at'        => now()->addDay(),
        ], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/auctions')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_browse_auctions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->makeAuction(['title' => 'Vintage Camera']);

        $this->actingAs($user)
            ->get(route('auctions.index'))
            ->assertOk()
            ->assertViewIs('auctions.index')
            ->assertSee('Vintage Camera');
    }

    public function test_draft_auctions_are_excluded_from_the_browse_list(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->makeAuction(['title' => 'Draft Lot', 'status' => 'draft']);

        $response = $this->actingAs($user)->get(route('auctions.index'));

        $response->assertOk();
        $response->assertDontSee('Draft Lot');
    }

    public function test_search_filters_by_title(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->makeAuction(['title' => 'Unique Alpha Item']);
        $this->makeAuction(['title' => 'Totally Different Beta']);

        $response = $this->actingAs($user)->get(route('auctions.index', ['q' => 'Alpha']));

        $response->assertOk();
        $response->assertSee('Unique Alpha Item');
        $response->assertDontSee('Totally Different Beta');
    }

    public function test_category_filter_scopes_results(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $electronics = AuctionCategory::create(['name' => 'Electronics', 'slug' => 'electronics']);
        $furniture   = AuctionCategory::create(['name' => 'Furniture', 'slug' => 'furniture']);

        $this->makeAuction(['title' => 'Laptop Lot', 'category_id' => $electronics->id]);
        $this->makeAuction(['title' => 'Sofa Lot', 'category_id' => $furniture->id]);

        $response = $this->actingAs($user)->get(route('auctions.index', ['category' => $electronics->id]));

        $response->assertOk();
        $response->assertSee('Laptop Lot');
        $response->assertDontSee('Sofa Lot');
    }
}
