<?php

namespace Tests\Feature;

use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Home, role by role. The page is read from the signed-in person's own rows, so each test builds the person's situation
 * (not a role flag) and checks that the page opens with what that situation needs, in words, with a link that goes there.
 */
class DashboardTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_with_no_team_is_redirected_to_team_creation(): void
    {
        // A plain factory user (no withPersonalTeam()) owns zero teams, so HasTeams::currentTeam()'s fallback also
        // resolves to null; the shell would crash on it, so Home sends the person to team creation instead.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('teams.create'));
    }

    public function test_a_new_user_sees_a_welcome_with_the_two_real_paths_and_no_zeros(): void
    {
        $user = $this->person();

        $response = $this->actingAs($user)->get('/dashboard')->assertOk()->assertViewIs('dashboard')->assertViewHas('isNew', true);

        $response->assertSee('Two ways to start')
            ->assertSee('href="'.route('auctions.index').'"', false)
            ->assertSee('href="'.route('seller.auctions.create').'"', false)
            ->assertDontSee('Needs you')
            ->assertDontSee('id="my-bids"', false)
            ->assertDontSee('AgriTech')
            ->assertDontSee('Planned');
        $this->assertStringNotContainsString('dot-kpi', $response->getContent());
    }

    public function test_the_old_farming_suite_and_metric_tiles_are_gone_for_everyone(): void
    {
        $user = $this->person();
        $this->bid($this->lot(), $user, '240.00');

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        foreach (['AgriTech', 'Intelligence Suite', 'Livestock', 'dot-kpi', 'dot-module'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    public function test_a_buyer_who_was_outbid_is_told_first_and_linked_to_the_place_to_bid_again(): void
    {
        $user = $this->person('Lerato');
        $rival = $this->person('Rival');
        $lot = $this->lot(['title' => 'Vintage Camera']);
        $this->bid($lot, $user, '220.00');
        $this->bid($lot, $rival, '260.00');

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('Needs you')
            ->assertSee('You were outbid on 1 lot that is still open.')
            ->assertSee('href="'.route('buyer.bids').'#outbid"', false)
            ->assertSee('id="my-bids"', false)
            ->assertSee('Vintage Camera')
            ->assertSeeText('R220.00')
            ->assertSeeText('R260.00')
            ->assertDontSee('Won and to pay');
    }

    public function test_a_leading_bidder_is_told_which_of_their_lots_close_within_a_day_and_not_that_they_were_outbid(): void
    {
        $user = $this->person();
        $closing = $this->lot(['title' => 'Closing Today', 'ends_at' => now()->addHours(3)]);
        $later = $this->lot(['title' => 'Closing Next Week', 'ends_at' => now()->addDays(7)]);
        $this->bid($closing, $user, '220.00');
        $this->bid($later, $user, '220.00');

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('A lot you lead closes within 24 hours.')
            ->assertSee('href="'.route('buyer.bids').'#leading"', false)
            ->assertSee('You lead')
            ->assertDontSee('outbid on');
    }

    public function test_a_winner_with_an_unpaid_sale_is_told_what_to_pay_and_when_with_a_link_to_the_sale(): void
    {
        config(['paystack.secret_key' => 'sk_test_unit_test_key_0123456789']); // payment is open
        $winner = $this->person('Sipho');
        $lot = $this->endedLot($winner, '400.00', ['title' => 'Oak Table']);
        $sale = $this->sale($lot, $winner, 'awaiting_payment', now()->addDays(2));

        $response = $this->actingAs($winner)->get('/dashboard')->assertOk();

        $response->assertSee('You won Oak Table. Pay R400.00 by')
            ->assertSee('Won and to pay')
            ->assertSee('href="'.route('settlements.show', $sale).'"', false)
            ->assertSee('Pay now')
            ->assertDontSee('id="my-bids"', false);
    }

    public function test_an_overdue_sale_is_urgent_and_the_winner_can_still_pay(): void
    {
        config(['paystack.secret_key' => 'sk_test_unit_test_key_0123456789']); // payment is open
        $winner = $this->person();
        $lot = $this->endedLot($winner, '400.00', ['title' => 'Late Lot']);
        $sale = $this->sale($lot, $winner, AuctionSettlement::PAYMENT_OVERDUE, now()->subDay());

        $this->actingAs($winner)->get('/dashboard')->assertOk()
            ->assertSee('Payment is overdue on Late Lot (R400.00)')
            ->assertSee('Payment overdue')
            ->assertSee('Pay now')
            ->assertSee('href="'.route('settlements.show', $sale).'"', false);
    }

    public function test_a_paid_sale_is_not_listed_as_owed(): void
    {
        $winner = $this->person();
        $lot = $this->endedLot($winner);
        $this->sale($lot, $winner, 'paid');

        $this->actingAs($winner)->get('/dashboard')->assertOk()
            ->assertDontSee('Won and to pay')
            ->assertDontSee('Pay R');
    }

    public function test_a_seller_with_a_reserve_decision_sees_it_first_and_can_act_on_it_from_home(): void
    {
        $seller = $this->person('Thandi');
        $bidder = $this->person('Bidder');
        $this->awaitingDecision($seller, $bidder);

        $response = $this->actingAs($seller)->get('/dashboard')->assertOk();

        $response->assertSee('A lot of yours ended below its reserve.')
            ->assertSee('href="#decisions"', false)
            ->assertSee('id="decisions"', false)
            ->assertSee('Awaiting your decision')
            ->assertSee('Reject the bid');
    }

    public function test_a_seller_sees_only_their_real_numbers_and_no_zeros(): void
    {
        $seller = $this->person('Pieter');
        $this->lot(['title' => 'Priced Lot', 'starting_price' => '1234.50', 'current_price' => '1500.00', 'ends_at' => now()->addHours(3)], $seller);
        $this->lot(['status' => 'draft'], $seller);

        $response = $this->actingAs($seller)->get('/dashboard')->assertOk();

        $response->assertSee('My lots')
            ->assertSee('Priced Lot')
            ->assertSeeText('R1,500.00')
            ->assertSee('Open for bids')
            ->assertSee('Closing within 24 hours')
            ->assertSee('Drafts, not published')
            ->assertDontSee('Awaiting your decision')
            ->assertDontSee('Scheduled')
            ->assertSee('You are selling 1 lot.');
    }

    public function test_a_seller_with_only_drafts_gets_the_drafts_line_and_no_empty_tables(): void
    {
        $seller = $this->person();
        $this->lot(['status' => 'draft'], $seller);

        $response = $this->actingAs($seller)->get('/dashboard')->assertOk();

        $response->assertSee('Drafts, not published')->assertDontSee('Open for bids');
        $this->assertStringNotContainsString('Your lots that are open for bids', $response->getContent());
    }

    public function test_a_seller_is_told_when_a_buyer_is_late_paying(): void
    {
        config(['paystack.secret_key' => 'sk_test_unit_test_key_0123456789']); // payment is open
        $seller = $this->person();
        $winner = $this->person();
        $lot = $this->endedLot($winner, '400.00', ['title' => 'Unpaid Lot', 'seller_id' => $seller->id]);
        $sale = $this->sale($lot, $winner, AuctionSettlement::PAYMENT_OVERDUE, now()->subDay());

        $this->actingAs($seller)->get('/dashboard')->assertOk()
            ->assertSee('The buyer has not paid for Unpaid Lot by the deadline.')
            ->assertSee('href="'.route('settlements.show', $sale).'"', false);
    }

    public function test_one_account_can_be_buyer_and_seller_at_once(): void
    {
        $user = $this->person();
        $rival = $this->person();
        $theirs = $this->lot(['title' => 'Their Lot']);
        $this->bid($theirs, $user, '220.00');
        $this->bid($theirs, $rival, '260.00');
        $this->lot(['title' => 'My Own Lot'], $user);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('id="my-bids"', false)->assertSee('Their Lot')
            ->assertSee('id="selling"', false)->assertSee('My Own Lot');
    }

    public function test_an_operator_is_told_how_many_payments_need_a_person_and_others_are_not(): void
    {
        $operator = User::factory()->withPersonalTeam()->platformOperator()->create();
        $buyer = $this->person();
        $this->bid($this->lot(), $buyer, '220.00');
        SettlementPayment::forceCreate(['provider' => 'paystack', 'reference' => 'GHOST-1', 'status' => SettlementPayment::UNMATCHED, 'reason' => 'unknown_reference', 'amount_cents' => 5000, 'currency' => 'ZAR']);
        SettlementPayment::forceCreate(['provider' => 'paystack', 'reference' => 'GHOST-2', 'status' => SettlementPayment::PAID, 'amount_cents' => 5000, 'currency' => 'ZAR']);
        $this->bid($this->lot(), $operator, '220.00');

        $this->actingAs($operator)->get('/dashboard')->assertOk()
            ->assertSee('One payment matched no sale and needs an operator.')
            ->assertSee('href="'.route('operator.settlement-payments.index', ['show' => 'attention']).'"', false);

        $this->actingAs($buyer)->get('/dashboard')->assertOk()->assertDontSee('needs an operator');
    }

    public function test_when_nothing_needs_the_person_the_page_says_so(): void
    {
        $user = $this->person();
        $this->bid($this->lot(['ends_at' => now()->addDays(5)]), $user, '220.00');

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Nothing needs you right now.');
    }

    public function test_watched_lots_closing_soon_and_platform_lots_closing_soon_have_their_own_sections(): void
    {
        $user = $this->person();
        $watched = $this->lot(['title' => 'Watched Soon', 'ends_at' => now()->addHours(5)]);
        $this->lot(['title' => 'Platform Soon', 'ends_at' => now()->addHours(6)]);
        $this->lot(['title' => 'Far Future Lot', 'ends_at' => now()->addDays(10)]);
        Watchlist::create(['user_id' => $user->id, 'auction_id' => $watched->id]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('Watching, closing in the next 24 hours')
            ->assertSee('Watched Soon')
            ->assertSee('Closing in the next 24 hours')
            ->assertSee('Platform Soon')
            ->assertDontSee('Far Future Lot')
            ->assertSee('href="'.route('buyer.watchlist').'"', false);
    }

    public function test_a_persons_own_lots_are_not_offered_back_to_them_as_lots_closing_soon(): void
    {
        $seller = $this->person();
        $this->lot(['title' => 'Mine Closing Soon', 'ends_at' => now()->addHours(5)], $seller);

        $html = $this->actingAs($seller)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="closing"', $html);
    }

    public function test_another_persons_activity_never_appears(): void
    {
        $me = $this->person();
        $other = $this->person();
        $lot = $this->lot(['title' => 'Someone Elses Bid']);
        $this->bid($lot, $other, '220.00');
        $sold = $this->endedLot($other, '400.00', ['title' => 'Someone Elses Sale']);
        $this->sale($sold, $other);
        $this->awaitingDecision($other, $this->person(), '300.00');

        $response = $this->actingAs($me)->get('/dashboard')->assertOk();

        $response->assertViewHas('isNew', true)->assertDontSee('Someone Elses Sale')->assertDontSee('Awaiting your decision');
        $this->assertStringNotContainsString('Someone Elses Bid', strip_tags(explode('id="closing"', $response->getContent())[0]));
    }

    public function test_home_has_no_dead_placeholder_links_and_uses_the_configured_currency(): void
    {
        $user = $this->person();
        $this->bid($this->lot(['title' => 'Dead Link Check', 'ends_at' => now()->addHours(2)]), $user, '1234.50');
        $this->actingAs($user);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(substr_count($html, 'data-pending-route'), substr_count($html, 'href="#"'), 'every href="#" is a marked pending route');
        $this->assertStringContainsString('1,234', $html);
        $this->assertStringContainsString('ds-money__sym">R<', $html);
        $this->assertStringNotContainsString('$1,', $html);
    }

    public function test_the_dashboard_cost_does_not_grow_with_the_number_of_lots(): void
    {
        $user = $this->person();
        $rival = $this->person();
        $make = function (int $n) use ($user, $rival) {
            foreach (range(1, $n) as $i) {
                $lot = $this->lot(['title' => "Lot $i", 'ends_at' => now()->addHours(2 + $i)]);
                $this->bid($lot, $user, '220.00');
                $this->bid($lot, $rival, '260.00');
                Watchlist::create(['user_id' => $user->id, 'auction_id' => $lot->id]);
            }
        };
        $count = function () use ($user) {
            $this->actingAs($user)->get('/dashboard')->assertOk(); // warm: the first request of a user also settles their current team
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get('/dashboard')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $make(2);
        $few = $count();
        $make(6);
        $many = $count();

        $this->assertSame($few, $many, 'queries on Home do not depend on how many lots there are');
    }
}
