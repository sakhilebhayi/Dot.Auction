<?php

namespace Tests\Feature\R42;

use App\Models\Auction;
use App\Notifications\AuctionEndingSoonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Design jury 1, P0 3: /auctions?page=99 said "No lots yet. Nothing has been published" while 22 lots existed.
 * A page past the end goes to the last page that has rows; a list that is truly empty keeps its own empty state.
 */
class PageRangeTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_browse_past_the_last_page_redirects_to_the_last_page_and_keeps_the_filters(): void
    {
        $viewer = $this->person('Viewer');
        $seller = $this->person('Seller');
        Auction::factory()->count(25)->create(['seller_id' => $seller->id]);

        // 25 lots at 12 a page: the last page is 3.
        $this->actingAs($viewer)->get(route('auctions.index', ['page' => 99, 'sort' => 'newest']))
            ->assertRedirect(route('auctions.index', ['sort' => 'newest', 'page' => 3]));

        $this->actingAs($viewer)->get(route('auctions.index', ['page' => 3]))->assertOk()
            ->assertDontSee('No lots yet');
    }

    public function test_browse_with_a_junk_page_value_still_shows_the_first_page(): void
    {
        $viewer = $this->person('Viewer');
        Auction::factory()->count(3)->create(['seller_id' => $this->person('Seller')->id]);

        foreach (['0', '-4', 'abc', '1.5', '[]'] as $page) {
            $this->actingAs($viewer)->get('/auctions?page='.$page)->assertOk()->assertDontSee('No lots yet');
        }
    }

    public function test_a_truly_empty_browse_keeps_its_empty_state_and_does_not_redirect_in_a_loop(): void
    {
        $viewer = $this->person('Viewer');

        $this->actingAs($viewer)->get(route('auctions.index'))->assertOk()->assertSee('No lots yet');
        $this->actingAs($viewer)->get(route('auctions.index', ['page' => 5]))->assertOk()->assertSee('No lots yet');
    }

    public function test_the_other_paged_lists_clamp_too(): void
    {
        $user = $this->person('Viewer');
        $seller = $this->person('Seller');
        Auction::factory()->count(20)->create(['seller_id' => $user->id]);
        foreach (range(1, 22) as $i) {
            $user->notify(new AuctionEndingSoonNotification(Auction::factory()->create(['seller_id' => $seller->id])));
        }

        $this->actingAs($user)->get(route('seller.auctions.index', ['page' => 99]))->assertRedirect();
        $this->actingAs($user)->get(route('notifications.index', ['page' => 99]))->assertRedirect(route('notifications.index', ['page' => 2]));
    }
}
