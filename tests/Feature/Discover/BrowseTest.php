<?php

namespace Tests\Feature\Discover;

use App\Models\Auction;
use App\Models\ReserveNotMetProposal;
use App\Models\Watchlist;
use App\Support\Display;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Browse: status vocabulary, filters that can say every state, sort, chips, cues for the viewer and a constant query count. */
class BrowseTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    /** One lot in every state, titled by state. */
    private function everyState(): array
    {
        $buyer = $this->person('Buyer');
        $seller = $this->person('Seller');
        $lots = [];
        $lots['live'] = $this->lot(['title' => 'State Live', 'ends_at' => now()->addDays(2)], $seller);
        $lots['ending'] = $this->lot(['title' => 'State Ending', 'ends_at' => now()->addMinutes(20)], $seller);
        $lots['scheduled'] = $this->lot(['title' => 'State Scheduled', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4)], $seller);
        $pending = $this->awaitingDecision($seller, $buyer);
        $pending->auction->forceFill(['title' => 'State Pending'])->save();
        $lots['pending'] = $pending->auction;
        $lots['sold'] = $this->endedLot($buyer, '400.00', ['title' => 'State Sold', 'seller_id' => $seller->id]);
        $lots['unsold'] = $this->lot(['title' => 'State Unsold', 'status' => 'ended', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()], $seller);
        $lots['cancelled'] = $this->lot(['title' => 'State Cancelled', 'status' => 'cancelled'], $seller);
        $lots['draft'] = $this->lot(['title' => 'State Draft', 'status' => 'draft'], $seller);

        return [$buyer, $lots];
    }

    public function test_a_lot_awaiting_the_sellers_decision_is_never_badged_cancelled(): void
    {
        [$buyer] = $this->everyState();

        $html = $this->actingAs($buyer)->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/State Pending.*?/s', $html);
        $card = $this->cardFor($html, 'State Pending');
        $this->assertStringContainsString('Awaiting seller decision', $card);
        $this->assertStringNotContainsString('Cancelled', $card);
        $this->assertStringContainsString('Cancelled', $this->cardFor($html, 'State Cancelled'));
    }

    public function test_every_lot_carries_the_right_status_word(): void
    {
        [$buyer] = $this->everyState();
        $html = $this->actingAs($buyer)->get(route('auctions.index'))->assertOk()->getContent();

        foreach ([
            'State Live' => 'Live', 'State Scheduled' => 'Scheduled', 'State Pending' => 'Awaiting seller decision',
            'State Sold' => 'Sold', 'State Unsold' => 'Not sold', 'State Cancelled' => 'Cancelled',
        ] as $title => $word) {
            $this->assertStringContainsString('>'.$word.'<', $this->cardFor($html, $title), "$title is $word");
        }
        $this->assertStringNotContainsString('State Draft', $html, 'drafts never show in browse');

        // The lot inside its final hour is promoted to the Closing soon row on the plain list; its card shows under its own tab.
        $ending = $this->actingAs($buyer)->get(route('auctions.index', ['state' => 'ending']))->assertOk()->getContent();
        $this->assertStringContainsString('>Closing soon<', $this->cardFor($ending, 'State Ending'));
    }

    public function test_lots_in_their_final_hour_get_their_own_row_and_are_not_listed_twice(): void
    {
        [$buyer] = $this->everyState();
        $html = $this->actingAs($buyer)->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="closing-soon-title"', $html);
        $this->assertSame(1, substr_count($html, 'State Ending</a>'), 'the lot is in the row and not again in the grid');
        $this->assertStringContainsString('Other lots', $html);

        // A search asks for something else: no promotion, the lot is an ordinary result.
        $search = $this->actingAs($buyer)->get(route('auctions.index', ['q' => 'State Ending']))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="closing-soon-title"', $search);
        $this->assertStringContainsString('State Ending', $search);

        // One search box on the page's own form, and the sort applies on change.
        $this->assertSame(1, substr_count($html, 'type="search"') - substr_count($html, 'id="shell-search"'), 'the page has one search field of its own (the shell has the other, hidden by CSS from 64rem up and below)');
        $this->assertStringContainsString('data-autosubmit', $html);
        $this->assertStringContainsString('<noscript>', $html, 'sorting works without JavaScript through a button');
    }

    public function test_a_lot_the_seller_rejected_is_not_sold_even_though_it_has_a_winning_bid(): void
    {
        $seller = $this->person();
        $bidder = $this->person();
        $proposal = $this->awaitingDecision($seller, $bidder);
        $proposal->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();
        $proposal->auction->forceFill(['status' => 'ended', 'title' => 'Rejected Offer'])->save();

        $html = $this->actingAs($bidder)->get(route('auctions.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>Not sold<', $this->cardFor($html, 'Rejected Offer'));

        $this->actingAs($bidder)->get(route('auctions.index', ['state' => 'unsold']))->assertSee('Rejected Offer');
        $this->actingAs($bidder)->get(route('auctions.index', ['state' => 'sold']))->assertDontSee('Rejected Offer');
    }

    public function test_each_state_filter_shows_exactly_its_own_lots(): void
    {
        [$buyer] = $this->everyState();

        $expect = [
            'live' => ['State Live', 'State Ending'],
            'ending' => ['State Ending'],
            'scheduled' => ['State Scheduled'],
            'pending' => ['State Pending'],
            'sold' => ['State Sold'],
            'unsold' => ['State Unsold'],
            'cancelled' => ['State Cancelled'],
        ];
        $all = ['State Live', 'State Ending', 'State Scheduled', 'State Pending', 'State Sold', 'State Unsold', 'State Cancelled'];

        foreach ($expect as $state => $titles) {
            $response = $this->actingAs($buyer)->get(route('auctions.index', ['state' => $state]))->assertOk();
            foreach ($all as $title) {
                in_array($title, $titles, true) ? $response->assertSee($title) : $response->assertDontSee($title);
            }
        }
    }

    public function test_the_old_status_query_string_still_lands_on_the_live_filter(): void
    {
        [$buyer] = $this->everyState();

        $this->actingAs($buyer)->get('/auctions?status=active')->assertOk()->assertSee('State Live')->assertDontSee('State Sold');
    }

    public function test_tab_counts_match_the_lots_each_tab_shows(): void
    {
        [$buyer] = $this->everyState();

        $response = $this->actingAs($buyer)->get(route('auctions.index'))->assertOk();
        $counts = $response->viewData('counts');

        $this->assertSame(7, $counts['all']);
        $this->assertSame(['live' => 2, 'ending' => 1, 'scheduled' => 1, 'pending' => 1, 'sold' => 1, 'unsold' => 1, 'cancelled' => 1], collect($counts)->except('all')->all());
    }

    public function test_default_order_is_live_first_and_soonest_closing_first(): void
    {
        $buyer = $this->person();
        $this->lot(['title' => 'Z Later', 'ends_at' => now()->addDays(3)]);
        $this->lot(['title' => 'Z Sooner', 'ends_at' => now()->addHours(5)]);
        $this->endedLot($buyer, '400.00', ['title' => 'Z Ended']);
        $this->lot(['title' => 'Z Cancelled', 'status' => 'cancelled']);
        $this->lot(['title' => 'Z Scheduled', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->actingAs($buyer)->get(route('auctions.index'))->assertSeeInOrder(['Z Sooner', 'Z Later', 'Z Scheduled', 'Z Ended', 'Z Cancelled']);
    }

    public function test_every_sort_orders_the_list(): void
    {
        $buyer = $this->person();
        $a = $this->lot(['title' => 'Sort A', 'ends_at' => now()->addDays(1)]);
        $b = $this->lot(['title' => 'Sort B', 'ends_at' => now()->addDays(2)]);
        $c = $this->lot(['title' => 'Sort C', 'ends_at' => now()->addDays(3)]);
        $this->bid($a, $buyer, '500.00');
        $this->bid($b, $buyer, '300.00');
        $this->bid($b, $this->person(), '320.00');
        $this->bid($b, $buyer, '340.00');
        $this->bid($c, $buyer, '420.00');
        $this->actingAs($buyer);

        $this->get(route('auctions.index', ['sort' => 'ending']))->assertSeeInOrder(['Sort A', 'Sort B', 'Sort C']);
        $this->get(route('auctions.index', ['sort' => 'newest']))->assertSeeInOrder(['Sort C', 'Sort B', 'Sort A']);
        $this->get(route('auctions.index', ['sort' => 'price_asc']))->assertSeeInOrder(['Sort B', 'Sort C', 'Sort A']);
        $this->get(route('auctions.index', ['sort' => 'price_desc']))->assertSeeInOrder(['Sort A', 'Sort C', 'Sort B']);
        $this->get(route('auctions.index', ['sort' => 'bids']))->assertSeeInOrder(['Sort B', 'Sort A']);
        $this->get(route('auctions.index', ['sort' => 'nonsense']))->assertOk()->assertSeeInOrder(['Sort A', 'Sort B', 'Sort C']);
    }

    public function test_the_card_says_starting_price_until_there_is_a_bid_and_current_price_after(): void
    {
        $buyer = $this->person();
        $this->lot(['title' => 'No Bids Yet', 'starting_price' => '200.00', 'current_price' => '200.00']);
        $bid = $this->lot(['title' => 'Has A Bid']);
        $this->bid($bid, $this->person(), '260.00');

        $html = $this->actingAs($buyer)->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Starting bid', $this->cardFor($html, 'No Bids Yet'));
        $this->assertStringContainsString('0 bids', $this->cardFor($html, 'No Bids Yet'));
        $this->assertStringContainsString('Current bid', $this->cardFor($html, 'Has A Bid'));
        $this->assertStringContainsString('1 bid<', $this->cardFor($html, 'Has A Bid'));
    }

    public function test_the_card_tells_the_viewer_when_they_lead_are_outbid_or_own_the_lot(): void
    {
        $me = $this->person();
        $rival = $this->person();
        $leading = $this->lot(['title' => 'I Lead This']);
        $this->bid($leading, $me, '260.00');
        $outbid = $this->lot(['title' => 'I Was Outbid']);
        $this->bid($outbid, $me, '260.00');
        $this->bid($outbid, $rival, '300.00');
        $this->lot(['title' => 'I Own This'], $me);
        $this->lot(['title' => 'Nothing To Me']);

        $html = $this->actingAs($me)->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('You lead', $this->cardFor($html, 'I Lead This'));
        $this->assertStringContainsString('Outbid', $this->cardFor($html, 'I Was Outbid'));
        $this->assertStringContainsString('Your lot', $this->cardFor($html, 'I Own This'));
        $card = $this->cardFor($html, 'Nothing To Me');
        foreach (['You lead', 'Outbid', 'Your lot'] as $word) {
            $this->assertStringNotContainsString($word, $card);
        }
    }

    public function test_the_watch_control_names_the_lot_and_is_absent_on_your_own_lot(): void
    {
        $me = $this->person();
        $theirs = $this->lot(['title' => 'Watch Target']);
        $this->lot(['title' => 'My Own Lot'], $me);
        Watchlist::create(['user_id' => $me->id, 'auction_id' => $this->lot(['title' => 'Already Watched'])->id]);

        $html = $this->actingAs($me)->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Watch', $this->cardFor($html, 'Watch Target'));
        $this->assertStringContainsString('Watch Target</span>', $this->cardFor($html, 'Watch Target'), 'the accessible name includes the lot title');
        $this->assertStringContainsString('Stop watching', $this->cardFor($html, 'Already Watched'));
        $this->assertStringNotContainsString('ds-lot__watch', $this->cardFor($html, 'My Own Lot'));
        $this->assertSame($theirs->title, 'Watch Target');
    }

    public function test_category_chips_link_to_the_filtered_list_and_counts_follow_the_state(): void
    {
        $cameras = $this->category('Cameras');
        $tools = $this->category('Tools');
        $this->lot(['title' => 'Camera One', 'category_id' => $cameras->id]);
        $this->lot(['title' => 'Camera Two', 'category_id' => $cameras->id, 'status' => 'cancelled']);
        $this->lot(['title' => 'Drill One', 'category_id' => $tools->id]);
        $viewer = $this->person();

        $response = $this->actingAs($viewer)->get(route('auctions.index', ['state' => 'live']))->assertOk();
        $this->assertHasLink($response->getContent(), ['state' => 'live', 'category' => (string) $cameras->id]);
        $response->assertSee('Cameras</span><span class="ds-chip__count">1</span>', false);

        $this->actingAs($viewer)->get(route('auctions.index', ['category' => $cameras->id]))
            ->assertSee('Camera One')->assertSee('Camera Two')->assertDontSee('Drill One');
    }

    public function test_active_filters_are_chips_that_remove_themselves_and_clear_all_resets(): void
    {
        $cat = $this->category('Cameras');
        $this->lot(['title' => 'Vintage Camera', 'category_id' => $cat->id]);
        $viewer = $this->person();

        $response = $this->actingAs($viewer)->get(route('auctions.index', ['q' => 'camera', 'category' => $cat->id, 'state' => 'live', 'sort' => 'newest']))->assertOk();

        $html = $response->getContent();
        $response->assertSee('Remove search:', false)->assertSee('Remove category:', false)->assertSee('Remove filter:', false)->assertSee('Clear all');
        $this->assertHasLink($html, ['category' => (string) $cat->id, 'state' => 'live', 'sort' => 'newest']);   // search removed
        $this->assertHasLink($html, ['q' => 'camera', 'state' => 'live', 'sort' => 'newest']);                    // category removed
        $this->assertHasLink($html, ['q' => 'camera', 'category' => (string) $cat->id, 'sort' => 'newest']);     // state removed
        $this->assertHasLink($html, ['sort' => 'newest']);                                                        // clear all keeps the sort

        $this->actingAs($viewer)->get(route('auctions.index'))->assertDontSee('Clear all');
    }

    public function test_every_filter_has_its_own_empty_state(): void
    {
        $viewer = $this->person();
        $this->lot(['title' => 'Only A Live Lot']);

        foreach ([
            'scheduled' => 'No lots are scheduled', 'pending' => 'No lots are waiting on a seller', 'sold' => 'No lots have sold yet',
            'unsold' => 'No lots ended unsold', 'cancelled' => 'No lots were cancelled', 'ending' => 'Nothing is closing soon',
        ] as $state => $title) {
            $this->actingAs($viewer)->get(route('auctions.index', ['state' => $state]))->assertOk()->assertSee($title);
        }
        $this->actingAs($viewer)->get(route('auctions.index', ['q' => 'zzz']))->assertOk()->assertSee('No lots match')->assertSee('Clear all filters');
    }

    public function test_the_result_count_names_what_was_asked(): void
    {
        $viewer = $this->person();
        $this->lot(['title' => 'Guitar One']);
        $this->lot(['title' => 'Guitar Two']);

        $text = $this->actingAs($viewer)->get(route('auctions.index', ['q' => 'guitar']))->getContent();
        $this->assertStringContainsString('2 lots matching "guitar"', preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text))));
    }

    public function test_pagination_keeps_the_filters(): void
    {
        $viewer = $this->person();
        Auction::factory()->count(30)->create(['seller_id' => $this->person()->id]);

        $this->actingAs($viewer)->get(route('auctions.index', ['state' => 'live', 'sort' => 'newest', 'page' => 2]))->assertOk()
            ->assertSee('state=live', false)->assertSee('sort=newest', false)->assertSee('Showing 13 to 24 of 30 lots');
    }

    public function test_queries_do_not_grow_with_the_number_of_lots_on_the_page(): void
    {
        $viewer = $this->person();
        $seller = $this->person();
        $count = function () use ($viewer) {
            $this->actingAs($viewer)->get(route('auctions.index'))->assertOk(); // warm
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get(route('auctions.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (Auction::factory()->count(3)->create(['seller_id' => $seller->id]) as $lot) {
            $this->bid($lot, $viewer, '260.00');
        }
        $few = $count();
        Auction::factory()->count(30)->create(['seller_id' => $seller->id]);
        $full = $count();

        $this->assertSame($few, $full, 'a full page of 12 lots costs the same number of queries as 3');
    }

    public function test_the_page_has_one_h1_a_unique_title_and_no_inline_style(): void
    {
        $viewer = $this->person();
        $this->lot();

        $html = $this->actingAs($viewer)->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<title>Browse lots | Dot.Auction</title>', $html);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}\b/i', explode('<main', $html)[1], 'no hex colour in the page body');
    }

    public function test_the_reserve_price_is_never_in_the_list(): void
    {
        $viewer = $this->person();
        $this->lot(['title' => 'Has A Reserve', 'reserve_price' => '987654.00']);

        $this->actingAs($viewer)->get(route('auctions.index'))->assertOk()->assertDontSee('987654')->assertDontSee('987,654');
    }

    public function test_the_state_helper_reads_the_rejected_flag_when_the_list_loaded_it(): void
    {
        $seller = $this->person();
        $bidder = $this->person();
        $proposal = $this->awaitingDecision($seller, $bidder);
        $proposal->forceFill(['status' => 'rejected'])->save();
        $proposal->auction->forceFill(['status' => 'ended'])->save();

        $plain = Auction::with('winningBid')->find($proposal->auction_id);
        $this->assertSame('sold', Display::lotState($plain), 'without the flag the old rule holds');

        $flagged = Auction::query()->select('auctions.*')->selectRaw("exists (select 1 from reserve_not_met_proposals rp where rp.auction_id = auctions.id and rp.status = 'rejected') as reserve_rejected")->with('winningBid')->find($proposal->auction_id);
        $this->assertSame('unsold', Display::lotState($flagged));
        $this->assertInstanceOf(ReserveNotMetProposal::class, $proposal);
    }

    /** The markup of the lot card whose title link says $title. */
    private function cardFor(string $html, string $title): string
    {
        $this->assertSame(1, preg_match('/<article\b(?:(?!<\/article>).)*?'.preg_quote($title, '/').'(?:(?!<\/article>).)*<\/article>/s', $html, $m), "a card for $title");

        return $m[0];
    }

    /** Some link on the page carries exactly these query parameters (in any order). */
    private function assertHasLink(string $html, array $params): void
    {
        preg_match_all('/href="([^"]*\/auctions\?[^"]*)"/', $html, $m);
        foreach ($m[1] as $href) {
            parse_str(html_entity_decode((string) parse_url($href, PHP_URL_QUERY)), $q);
            ksort($q);
            $want = $params;
            ksort($want);
            if ($q === $want) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail('no link with '.json_encode($params).' among '.json_encode($m[1]));
    }
}
