<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\BidPanel;
use App\Livewire\Auctions\WatchlistButton;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Services\Auctions\AuctionSnapshot;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\BidPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-42 W4: the bid panel as a person meets it. Every refusal says why in words under the amount field, a bid is judged
 * and confirmed in two steps, a second press is not a second bid, and the panel follows the lot without a reload.
 */
class BidPanelFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Starting price 200, step 20, so the first valid bid is R220.00. */
    private function lot(array $overrides = []): Auction
    {
        return Auction::factory()->create($overrides);
    }

    private function buyer(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function panel(Auction $auction, ?User $viewer = null)
    {
        return Livewire::actingAs($viewer ?? $this->buyer())->test(BidPanel::class, ['auction' => $auction]);
    }

    // ---- the amount field says what is wrong

    public function test_every_bad_amount_is_explained_under_the_field_and_nothing_is_placed(): void
    {
        $auction = $this->lot();
        $cases = [
            ['', 'Enter the amount you want to bid.'],
            ['   ', 'Enter the amount you want to bid.'],
            ['-5', 'Your bid must be more than zero.'],
            ['0', 'Your bid must be more than zero.'],
            ['abc', 'Enter your bid as a number, for example 250 or 250.50.'],
            ['12abc', 'Enter your bid as a number, for example 250 or 250.50.'],
            ['1e400', 'Enter your bid as a number, for example 250 or 250.50.'],
            ['100', 'Your bid is too low. The next valid bid is R220.00.'],
            ['219.99', 'Your bid is too low. The next valid bid is R220.00.'],
            ['220.555', 'Bid amounts can have at most 2 decimal places.'],
            ['999999999', 'Bids above R100,000,000.00 are not accepted.'],
        ];

        foreach ($cases as [$typed, $message]) {
            foreach (['review', 'placeBid'] as $step) {
                $panel = $this->panel($auction)->set('bidAmount', $typed)->call($step);

                $panel->assertSet('error', $message)->assertSet('errorField', 'bidAmount')->assertSet('success', null)
                    ->assertHasErrors('bidAmount')->assertSee($message)->assertSet('confirmAmount', '')
                    ->assertSet('announcement', $message)->assertDispatched('lot-bid-focus')->assertNotDispatched('ds-dialog-open');
            }
        }

        $this->assertSame(0, Bid::count());
    }

    public function test_the_field_does_not_use_the_browsers_own_validation_that_would_hide_the_message(): void
    {
        $html = $this->panel($this->lot())->html();

        $this->assertStringContainsString('novalidate', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*\smin=/', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('data-prefill="220.00"', $html, 'the minimum is prefilled');
    }

    public function test_a_currency_symbol_spaces_and_thousands_commas_are_accepted(): void
    {
        $auction = $this->lot(['starting_price' => '1000.00', 'current_price' => '1000.00', 'bid_increment' => '100.00']);

        foreach (['R1,100', 'R 1 100.00', '1,100.00', ' 1100 '] as $typed) {
            $this->panel($auction)->set('bidAmount', $typed)->call('review')
                ->assertSet('error', null)->assertSet('confirmAmount', 'R1,100.00')->assertSet('bidAmount', '1100.00');
        }
    }

    // ---- two steps: judge, then place

    public function test_review_opens_the_confirm_dialog_with_the_amount_and_places_nothing(): void
    {
        $auction = $this->lot();

        $this->panel($auction)->set('bidAmount', '250')->call('review')
            ->assertSet('error', null)->assertSet('confirmAmount', 'R250.00')
            ->assertDispatched('ds-dialog-open', id: 'confirm-bid-'.$auction->id)
            ->assertSee('Place a bid of R250.00?')->assertSee('Place bid of R250.00');

        $this->assertSame(0, Bid::count());
        $this->assertEquals(200, $auction->fresh()->current_price);
    }

    public function test_confirming_places_the_bid_closes_the_dialog_and_says_you_are_leading(): void
    {
        $auction = $this->lot();
        $buyer = $this->buyer();

        $panel = $this->panel($auction, $buyer)->set('bidAmount', '250')->call('review')->call('placeBid');

        $panel->assertSet('error', null)->assertSet('success', 'Your bid of R250.00 is in.')
            ->assertDispatched('ds-dialog-close', id: 'confirm-bid-'.$auction->id)
            ->assertSee('You are leading')->assertSet('bidAmount', '270.00')
            ->assertSet('announcement', 'Your bid of R250.00 is in. You are leading.');

        $this->assertSame($buyer->id, $auction->fresh()->winningBid->bidder_id);
        // The leader is not invited to bid against themselves: no amount field, no Place bid button.
        $panel->assertDontSee('Place bid')->assertDontSeeHtml('data-ds-bid-input');
    }

    public function test_a_second_press_of_the_confirm_button_is_not_a_second_bid_and_not_an_error(): void
    {
        $auction = $this->lot();

        $panel = $this->panel($auction)->set('bidAmount', '250')->call('review')->call('placeBid')->call('placeBid');

        $panel->assertSet('error', null)->assertSet('success', 'Your bid of R250.00 is in.');
        $this->assertSame(1, Bid::count());
    }

    public function test_a_replay_with_the_same_token_after_a_reload_reads_as_the_bid_that_went_through(): void
    {
        $auction = $this->lot();
        $buyer = $this->buyer();
        $token = 'same-form-token';
        app(BidPlacer::class)->place($auction, $buyer, '250.00', $token);

        // A panel that still holds the old token and an amount that is now below the minimum.
        $panel = $this->panel($auction, $buyer)->set('submissionToken', $token)->set('bidAmount', '250')->call('placeBid');

        $this->assertSame(1, Bid::count());
        // The viewer already leads, so the form is not offered and the request is refused in words, not stacked on.
        $this->assertNotNull($panel->get('error'));
    }

    public function test_a_price_that_moved_while_deciding_is_explained(): void
    {
        $auction = $this->lot();
        $panel = $this->panel($auction)->set('bidAmount', '220')->call('review');

        app(BidPlacer::class)->place($auction, $this->buyer(), '240.00'); // someone else, first

        $panel->call('placeBid')->assertSet('error', 'Someone bid first. The next valid bid is now R260.00.')->assertSet('bidAmount', '260.00');
        $this->assertSame(1, Bid::count());
    }

    public function test_a_repeat_of_an_old_bid_amount_is_refused_in_plain_words(): void
    {
        // BidPlacer refuses a bidder's repeat of an amount they already bid. The panel's own check (too low) is reached first for
        // any amount below the minimum, which a repeat always is; both are plain words under the field.
        $auction = $this->lot();
        $buyer = $this->buyer();
        $rival = $this->buyer();
        app(BidPlacer::class)->place($auction, $buyer, '220.00');
        app(BidPlacer::class)->place($auction, $rival, '240.00');

        $this->panel($auction, $buyer)->set('bidAmount', '220')->call('review')
            ->assertSet('error', 'Your bid is too low. The next valid bid is R260.00.')->assertSet('errorField', 'bidAmount');
    }

    public function test_rate_limited_bids_say_when_to_try_again(): void
    {
        config(['auction.bid_rate_limit' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
        $auction = $this->lot();
        $buyer = $this->buyer();
        RateLimiter::clear('bid:'.$buyer->id);

        $panel = $this->panel($auction, $buyer);
        $panel->set('bidAmount', '220')->call('placeBid');
        app(BidPlacer::class)->place($auction, $this->buyer(), '240.00');
        $panel->set('bidAmount', '260')->call('placeBid');
        app(BidPlacer::class)->place($auction, $this->buyer(), '280.00');
        $panel->set('bidAmount', '300')->call('placeBid');

        $this->assertMatchesRegularExpression('/^Too many bids\. Try again in \d+ seconds\.$/', (string) $panel->get('error'));
        $this->assertNull($panel->get('errorField'));
        $panel->assertSee('Try again in');
    }

    public function test_a_lot_that_closed_meanwhile_says_so_and_the_panel_moves_to_the_ended_state(): void
    {
        $auction = $this->lot();
        $panel = $this->panel($auction)->set('bidAmount', '220')->call('review');

        $auction->forceFill(['ends_at' => now()->subMinute()])->save();

        $panel->call('placeBid')->assertSet('error', 'This lot has closed. Your bid was not placed.')
            ->assertSee('Bidding has closed')->assertDontSeeHtml('data-ds-bid-input');
        $this->assertSame(0, Bid::count());
    }

    public function test_a_seller_cannot_bid_and_is_not_offered_the_form(): void
    {
        $auction = $this->lot();

        $panel = $this->panel($auction, $auction->seller)->set('bidAmount', '250');

        $panel->assertSee('This is your lot')->assertDontSeeHtml('data-ds-bid-input');
        $panel->call('review')->assertSet('error', 'You cannot bid on your own auction.');
        $panel->call('placeBid')->assertSet('error', 'You cannot bid on your own auction.');
        $this->assertSame(0, Bid::count());
    }

    // ---- live without Reverb

    public function test_being_outbid_shows_without_a_reload_and_is_announced(): void
    {
        $auction = $this->lot();
        $me = $this->buyer();
        $rival = $this->buyer();
        $panel = $this->panel($auction, $me)->set('bidAmount', '240')->call('placeBid');
        $panel->assertSee('You are leading');
        $before = $panel->instance()->snapshot();
        $this->assertTrue($before['leading_bidder_is_me']);

        app(BidPlacer::class)->place($auction, $rival, '300.00');

        $after = $panel->instance()->snapshot();
        $this->assertFalse($after['leading_bidder_is_me'], 'the poll sees the change');
        $this->assertSame(30000, $after['current_price_cents']);

        $panel->call('refreshState')
            ->assertSee('You were outbid')
            ->assertSee('Your last bid was R240.00. To take the lead, bid at least R320.00, which is R80.00 more.')
            ->assertSet('success', null)
            ->assertSet('announcement', 'You were outbid. The price is now R300.00. The next valid bid is R320.00.')
            ->assertSet('bidAmount', '320.00')
            ->assertSeeHtml('data-ds-bid-input');
    }

    public function test_a_refresh_keeps_what_the_viewer_typed_unless_it_is_now_too_low(): void
    {
        $auction = $this->lot();
        $panel = $this->panel($auction);

        app(BidPlacer::class)->place($auction, $this->buyer(), '240.00');
        $panel->call('refreshState', '400')->assertSet('bidAmount', '400');

        app(BidPlacer::class)->place($auction, $this->buyer(), '500.00');
        $panel->call('refreshState', '400')->assertSet('bidAmount', '520.00');
    }

    public function test_a_quiet_refresh_announces_nothing_and_a_new_bid_announces_the_price_once(): void
    {
        $auction = $this->lot();
        $panel = $this->panel($auction);

        $panel->call('refreshState')->assertSet('announcement', '');

        app(BidPlacer::class)->place($auction, $this->buyer(), '240.00');
        $panel->call('refreshState')->assertSet('announcement', 'New bid: R240.00.');
        $panel->call('refreshState')->assertSet('announcement', '');
    }

    public function test_the_panel_switches_to_the_ended_state_from_the_server_when_the_clock_runs_out(): void
    {
        config(['auction.snipe_window_minutes' => 0]); // no late-bid extension: the clock really runs out
        $auction = $this->lot(['ends_at' => now()->addMinute()]);
        $me = $this->buyer();
        $panel = $this->panel($auction, $me)->set('bidAmount', '220')->call('placeBid');

        $this->travel(2)->minutes();

        $snap = $panel->instance()->snapshot();
        $this->assertSame('ended', $snap['status'], 'the snapshot says ended the moment ends_at passes');

        // The settlement job has not run: the panel says the result is being recorded and does not claim a sale.
        $panel->call('refreshState')->assertSee('Bidding has closed')->assertSee('The result is being recorded')
            ->assertDontSee('You won this lot')->assertDontSeeHtml('data-ds-bid-input')->assertSeeHtml('data-settling');

        app(AuctionSettler::class)->settle($auction->fresh());
        $panel->call('refreshState')->assertSee('You won this lot')->assertDontSeeHtml('data-settling');
    }

    public function test_the_signature_in_the_markup_matches_the_snapshot_the_poller_reads(): void
    {
        $auction = $this->lot();
        $me = $this->buyer();
        $panel = $this->panel($auction, $me);
        $s = $panel->instance()->snapshot();
        $expected = implode('|', [$s['status'], $s['current_price_cents'], $s['bid_count'], $s['ends_at'], '0']);

        $panel->assertSeeHtml('data-sig="'.$expected.'"');
    }

    public function test_the_echo_listener_is_only_registered_when_reverb_is_the_broadcaster(): void
    {
        config(['broadcasting.default' => 'log', 'auction.realtime.enabled' => true]);
        $this->assertSame([], $this->listenersOf($this->lot()));

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'auction.realtime.enabled' => true]);
        $auction = $this->lot();
        $this->assertSame(['echo:auction.'.$auction->id.',.bid.placed' => 'refreshState'], $this->listenersOf($auction));

        config(['auction.realtime.enabled' => false]);
        $this->assertSame([], $this->listenersOf($auction));
    }

    /** @return array<string, string> */
    private function listenersOf(Auction $auction): array
    {
        $component = $this->panel($auction)->instance();
        $method = new \ReflectionMethod($component, 'getListeners');

        return $method->invoke($component);
    }

    // ---- bid history

    public function test_the_history_lists_ten_with_a_show_all_for_the_rest_and_marks_your_own_rows(): void
    {
        $auction = $this->lot();
        $me = $this->buyer();
        $other = User::factory()->withPersonalTeam()->create(['name' => 'Thandiwe Zulu-Nkosi']);
        for ($i = 1; $i <= 12; $i++) {
            app(BidPlacer::class)->place($auction, $i % 4 === 0 ? $me : $other, (string) (200 + 20 * $i).'.00');
        }

        $panel = $this->panel($auction, $me);
        $panel->assertSee('Show all 12 bids')->assertSee('Thandiwe Z.')->assertDontSee('Zulu-Nkosi')->assertSee('Your bid');
        $this->assertCount(10, $panel->instance()->recentBids);

        $panel->call('toggleAllBids')->assertSee('Show the latest 10');
        $this->assertCount(12, $panel->instance()->recentBids);
    }

    // ---- Buy Now

    public function test_buy_now_asks_first_then_buys_once_and_points_to_payment(): void
    {
        $auction = $this->lot(['buy_now_price' => '500.00']);
        $buyer = $this->buyer();

        $panel = $this->panel($auction, $buyer)->assertSee('Buy now for R500.00?')->assertSee('Keep bidding');
        $this->assertSame(0, Bid::count(), 'opening the dialog buys nothing');

        $panel->call('buyNow')->assertSet('purchased', true)->assertSet('success', 'You bought this lot for R500.00.')
            ->assertSee('You bought this lot with Buy Now')->assertDispatched('ds-dialog-close', id: 'confirm-buy-'.$auction->id);
        $panel->call('buyNow')->assertSet('purchased', true)->assertSet('error', null);

        $this->assertSame(1, Bid::count());
        $this->assertSame('ended', $auction->fresh()->status);
    }

    public function test_buy_now_that_is_no_longer_possible_says_why(): void
    {
        $auction = $this->lot(['buy_now_price' => '500.00']);
        $panel = $this->panel($auction);
        app(BidPlacer::class)->place($auction, $this->buyer(), '500.00');

        $panel->call('buyNow')->assertSet('purchased', false)
            ->assertSet('error', 'Bidding has reached the Buy Now price, so Buy Now is gone. Nothing was bought.');
    }

    // ---- watch

    public function test_the_watch_button_names_the_lot_and_the_state_and_is_not_offered_to_the_seller(): void
    {
        $auction = $this->lot(['title' => 'Signed guitar']);
        $buyer = $this->buyer();

        $watch = Livewire::actingAs($buyer)->test(WatchlistButton::class, ['auction' => $auction])
            ->assertSeeHtml('Watch<span class="sr-only"> Signed guitar</span>');
        $watch->call('toggle')->assertSet('message', 'Added to your watchlist.')
            ->assertSeeHtml('Watching<span class="sr-only"> Signed guitar, press to stop watching</span>');
        $watch->call('toggle')->assertSet('message', 'Removed from your watchlist.');

        Livewire::actingAs($auction->seller)->test(WatchlistButton::class, ['auction' => $auction])->call('toggle')->assertSet('watching', false);
        $this->assertDatabaseCount('watchlists', 0);

        $this->actingAs($auction->seller)->get(route('auctions.show', $auction))->assertOk()->assertDontSeeHtml('Watch<span class="sr-only"> Signed guitar');
        $this->actingAs($buyer)->get(route('auctions.show', $auction))->assertOk()->assertSeeHtml('Watch<span class="sr-only"> Signed guitar');
    }

    public function test_snapshot_has_the_contract_the_poller_needs(): void
    {
        $auction = $this->lot();
        AuctionSnapshot::forget($auction->id);
        $s = $this->panel($auction)->instance()->snapshot();

        foreach (['status', 'current_price_cents', 'bid_count', 'ends_at', 'leading_bidder_is_me', 'server_now_ms', 'seconds_remaining', 'poll_seconds'] as $key) {
            $this->assertArrayHasKey($key, $s);
        }
        $this->assertArrayNotHasKey('reserve_price', $s);
    }
}
