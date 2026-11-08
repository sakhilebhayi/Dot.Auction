<?php

namespace Tests\Feature\Seller;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Support\SellerLots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * R-42 UI: the seller's lot list. Tabs and counts by state, the next action on every row, the reserve decision on the
 * row that needs it, phone-friendly markup, and a page that costs the same number of queries for 2 lots or 12.
 */
class SellerListingsPageTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private function seller(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /** One lot in every state the tabs know about, for $seller. */
    private function lotsInEveryState(User $seller): array
    {
        $make = fn (array $attributes) => Auction::factory()->create(array_merge(['seller_id' => $seller->id], $attributes));

        return [
            'live' => $make(['title' => 'Live Lot']),
            'ending' => $make(['title' => 'Ending Lot', 'ends_at' => now()->addMinutes(20)]),
            'scheduled' => Auction::factory()->scheduled()->create(['seller_id' => $seller->id, 'title' => 'Scheduled Lot']),
            'draft' => Auction::factory()->draft()->create(['seller_id' => $seller->id, 'title' => 'Draft Lot']),
            'ended' => Auction::factory()->ended()->create(['seller_id' => $seller->id, 'title' => 'Ended Lot']),
            'cancelled' => $make(['title' => 'Cancelled Lot', 'status' => 'cancelled']),
            'pending' => $make(['title' => 'Pending Lot', 'status' => 'pending_review', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]),
        ];
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('seller.auctions.index'))->assertRedirect('/login');
    }

    public function test_the_tab_counts_are_real_and_each_tab_shows_exactly_its_lots(): void
    {
        $seller = $this->seller();
        $this->lotsInEveryState($seller);
        Auction::factory()->create(['title' => 'Someone Elses Lot']);

        $counts = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->viewData('counts');

        $this->assertSame(['all' => 7, 'pending' => 1, 'live' => 2, 'ending' => 1, 'scheduled' => 1, 'draft' => 1, 'ended' => 1, 'cancelled' => 1], $counts);

        $titles = ['pending' => 'Pending Lot', 'live' => 'Live Lot', 'ending' => 'Ending Lot', 'scheduled' => 'Scheduled Lot', 'draft' => 'Draft Lot', 'ended' => 'Ended Lot', 'cancelled' => 'Cancelled Lot'];
        foreach ($titles as $state => $title) {
            $page = $this->actingAs($seller)->get(route('seller.auctions.index', ['state' => $state]))->assertOk()->assertSee($title);
            foreach (array_diff($titles, [$state => $title]) as $other) {
                // "Live Lot" is a substring-free title among the others, but "Ending Lot" must not leak into the live tab's heading text check.
                if ($state === 'live' && $other === 'Ending Lot') {
                    continue;
                }
                $page->assertDontSee($other);
            }
            $page->assertDontSee('Someone Elses Lot');
            $this->assertSame($counts[$state], $page->viewData('auctions')->total(), "the $state tab lists as many lots as its count says");
        }
    }

    public function test_the_counts_and_the_filters_are_the_same_query_logic(): void
    {
        $seller = $this->seller();
        $this->lotsInEveryState($seller);

        $counts = SellerLots::counts($seller->id, now(), 60);

        foreach (array_keys(SellerLots::STATES) as $state) {
            $listed = SellerLots::apply(Auction::query()->where('seller_id', $seller->id), $state, now(), 60)->count();
            $this->assertSame($counts[$state], $listed, $state);
        }
    }

    public function test_an_unknown_state_shows_everything_and_a_tab_with_nothing_in_it_is_not_offered(): void
    {
        $seller = $this->seller();
        Auction::factory()->draft()->create(['seller_id' => $seller->id, 'title' => 'Only Draft']);

        $page = $this->actingAs($seller)->get(route('seller.auctions.index', ['state' => 'nonsense']))->assertOk()->assertSee('Only Draft');
        $html = $page->getContent();
        $this->assertStringContainsString('Drafts', $html);
        $this->assertStringNotContainsString('Cancelled', $html);
        $this->assertStringNotContainsString('Needs your decision', $html);
    }

    public function test_the_page_has_a_heading_a_unique_title_and_no_hidden_columns(): void
    {
        $seller = $this->seller();
        Auction::factory()->create(['seller_id' => $seller->id]);

        $html = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<title>My lots | ', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*My lots\s*<\/h1>/', $html);
        $this->assertStringNotContainsString('table-scroll', $html);
        $this->assertMatchesRegularExpression('/ds-table\s+is-stackable/', $html, 'a phone gets a card per lot, not a sideways scroll');
        $main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
        $this->assertStringNotContainsString('style="', preg_replace('/<livewire-placeholder.*?>/s', '', $main), 'no inline styles in the page itself');
    }

    public function test_every_row_shows_its_next_action(): void
    {
        $seller = $this->seller();
        $lots = $this->lotsInEveryState($seller);

        $html = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->getContent();

        // Draft: publish (behind a confirm dialog), edit, delete in the menu.
        $this->assertStringContainsString(route('seller.auctions.publish', $lots['draft']), $html);
        $this->assertStringContainsString(route('seller.auctions.edit', $lots['draft']), $html);
        $this->assertStringContainsString(route('seller.auctions.destroy', $lots['draft']), $html);
        // A lot with no bids can be cancelled from the menu; a draft and an ended lot cannot.
        $this->assertStringContainsString(route('seller.auctions.cancel', $lots['live']), $html);
        $this->assertStringNotContainsString(route('seller.auctions.cancel', $lots['ended']), $html);
        $this->assertStringNotContainsString(route('seller.auctions.cancel', $lots['draft']), $html);
        // Everything else can be opened.
        $this->assertStringContainsString(route('auctions.show', $lots['ended']), $html);
        // Destructive things ask first, with the verb on the button.
        $this->assertStringContainsString('Delete draft', $html);
        $this->assertStringContainsString('data-ds-confirm-form', $html);
    }

    public function test_a_lot_with_a_bid_cannot_be_cancelled_from_the_list(): void
    {
        $seller = $this->seller();
        $lot = Auction::factory()->create(['seller_id' => $seller->id]);
        Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => User::factory()->create()->id, 'amount' => '220.00', 'is_winning' => true]);
        $lot->forceFill(['current_price' => '220.00'])->save();

        $html = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('seller.auctions.cancel', $lot), $html);
    }

    public function test_a_lot_waiting_for_the_sellers_decision_carries_the_real_decision_panel(): void
    {
        $seller = $this->seller();
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'title' => 'Below Reserve', 'status' => 'pending_review', 'reserve_price' => '500.00', 'current_price' => '300.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]);
        $bid = Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => User::factory()->create(['name' => 'Bea Bidder'])->id, 'amount' => '300.00', 'is_winning' => true]);
        $proposal = ReserveNotMetProposal::create(['auction_id' => $lot->id, 'bid_id' => $bid->id, 'reserve_price' => '500.00', 'status' => 'pending']);

        $page = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()
            ->assertSee('Your decision')
            ->assertSee('Reject the bid')
            ->assertSee('Bea B.');
        $this->assertStringContainsString('reserve-decision-panel', $page->getContent());

        // The panel on the list decides the same way the dashboard's does.
        \Livewire\Livewire::actingAs($seller)->test(\App\Livewire\Auctions\ReserveDecisionPanel::class, ['proposal' => $proposal])->call('reject');
        $this->assertSame('rejected', $proposal->fresh()->status);

        // Another seller never sees it.
        $this->actingAs($this->seller())->get(route('seller.auctions.index'))->assertOk()->assertDontSee('Below Reserve');
    }

    public function test_a_lot_whose_top_bid_the_seller_rejected_reads_not_sold_on_the_sellers_own_list(): void
    {
        $seller = $this->seller();
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'title' => 'Rejected Rug', 'status' => 'ended', 'reserve_price' => '500.00', 'current_price' => '300.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]);
        $bid = Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => User::factory()->create()->id, 'amount' => '300.00', 'is_winning' => true]);
        ReserveNotMetProposal::create(['auction_id' => $lot->id, 'bid_id' => $bid->id, 'reserve_price' => '500.00', 'status' => 'rejected']);

        $row = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->viewData('auctions')->first();

        $this->assertSame('unsold', \App\Support\Display::lotState($row));
        $this->actingAs($seller)->get(route('seller.auctions.index'))->assertSee('Not sold')->assertDontSee('Sold for');
    }

    public function test_a_sold_lot_links_to_its_payment_and_says_where_the_payment_is(): void
    {
        [$settlement, , $seller] = $this->settlement(50000);
        $settlement->auction->update(['title' => 'Sold Sideboard']);

        $html = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->assertSee('Sold Sideboard')->assertSee('Awaiting payment')->getContent();

        $this->assertStringContainsString(route('settlements.show', $settlement), $html);
        $this->assertStringContainsString('View payment', $html);
    }

    public function test_the_empty_states(): void
    {
        $this->actingAs($this->seller())->get(route('seller.auctions.index'))->assertOk()
            ->assertSee('You have not listed anything yet')->assertSee(route('seller.auctions.create'), false);

        $seller = $this->seller();
        Auction::factory()->create(['seller_id' => $seller->id]);
        $this->actingAs($seller)->get(route('seller.auctions.index', ['state' => 'cancelled']))->assertOk()
            ->assertSee('No lots in this view')->assertSee('Show all lots');
    }

    public function test_the_list_is_paginated_and_keeps_the_tab_in_the_pager(): void
    {
        $seller = $this->seller();
        Auction::factory()->count(17)->create(['seller_id' => $seller->id]);

        $page = $this->actingAs($seller)->get(route('seller.auctions.index', ['state' => 'live']))->assertOk();

        $this->assertSame(15, $page->viewData('auctions')->count());
        $this->assertStringContainsString('state=live', html_entity_decode($page->getContent()));
        $this->actingAs($seller)->get(route('seller.auctions.index', ['state' => 'live', 'page' => 2]))->assertOk();
    }

    public function test_the_list_costs_the_same_number_of_queries_for_two_lots_or_twelve(): void
    {
        $seller = $this->seller();
        $count = function () use ($seller): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        Auction::factory()->count(2)->create(['seller_id' => $seller->id]);
        $count(); // the first request of a test also pays for session and auth set-up; measure from the second
        $few = $count();

        foreach (range(1, 10) as $i) {
            $auction = Auction::factory()->create(['seller_id' => $seller->id]);
            \App\Models\AuctionImage::create(['auction_id' => $auction->id, 'path' => "x/$i.jpg", 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 4, 'height' => 3, 'position' => 0]);
            Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => User::factory()->create()->id, 'amount' => '220.00', 'is_winning' => $i % 2 === 0]);
        }
        $many = $count();

        $this->assertSame($few, $many, "the list ran $few queries for 2 lots and $many for 12");
    }
}
