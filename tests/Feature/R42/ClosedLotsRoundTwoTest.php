<?php

namespace Tests\Feature\R42;

use App\Livewire\Auctions\WatchlistButton;
use App\Livewire\Buyer\WatchToggle;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\BidPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsSettlements;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Design jury 2, P1: a lot that has closed stays on Home until its result lands, nothing offers Watch on a lot with nothing left to watch
 * for, and a cancelled sale is told once with no payment link.
 */
class ClosedLotsRoundTwoTest extends TestCase
{
    use BuildsDiscoverWorld;
    use BuildsSettlements;
    use RefreshDatabase;

    // ---- Home keeps closed lots until the result lands

    public function test_a_lot_closed_by_the_clock_with_no_result_yet_stays_on_home(): void
    {
        $me = $this->person();
        $lot = $this->lot(['title' => 'Result Pending Rug', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subMinutes(3)]);
        $this->bid($lot, $me, '300.00');

        $page = $this->actingAs($me)->get('/dashboard')->assertOk();

        $page->assertSee('Result Pending Rug');
        $page->assertSee('Closed, result pending');
        $page->assertSee('Bidding closed on Result Pending Rug. The result is being recorded');
    }

    public function test_a_lot_waiting_on_the_seller_stays_on_home_for_the_person_whose_bid_is_on_top(): void
    {
        $seller = $this->person();
        $me = $this->person();
        $proposal = $this->awaitingDecision($seller, $me);
        $proposal->auction->update(['title' => 'Decision Pending Lamp']);

        $page = $this->actingAs($me)->get('/dashboard')->assertOk();

        $page->assertSee('Decision Pending Lamp');
        $page->assertSee('Awaiting seller decision');
        $page->assertSee('The seller is deciding on Decision Pending Lamp, where you hold the top bid');
    }

    public function test_a_lot_waiting_on_the_seller_is_not_on_home_for_someone_who_was_outbid_on_it(): void
    {
        $seller = $this->person();
        $top = $this->person();
        $other = $this->person();
        $proposal = $this->awaitingDecision($seller, $top);
        $proposal->auction->update(['title' => 'Not Mine Lamp']);
        \App\Models\Bid::forceCreate(['auction_id' => $proposal->auction_id, 'bidder_id' => $other->id, 'amount' => '200.00', 'is_winning' => false]);

        $this->actingAs($other)->get('/dashboard')->assertOk()->assertDontSee('Not Mine Lamp');
    }

    public function test_a_won_lot_stays_on_home_until_it_is_paid(): void
    {
        $this->disablePaystack();
        [$settlement, $winner] = $this->settlement(75000);
        $settlement->auction->update(['title' => 'Won And Unpaid Chair']);

        $this->actingAs($winner)->get('/dashboard')->assertOk()
            ->assertSee('Won And Unpaid Chair')
            ->assertSee('Waiting for payment to open');

        $settlement->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->actingAs($winner)->get('/dashboard')->assertOk()->assertDontSee('Won And Unpaid Chair');
    }

    // ---- no Watch on a lot with nothing left to watch for

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function closedLots(): array
    {
        return [
            'closed by the clock' => [['status' => 'active', 'starts_at' => '-2 days', 'ends_at' => '-5 minutes']],
            'ended' => [['status' => 'ended', 'starts_at' => '-3 days', 'ends_at' => '-1 day']],
            'awaiting the seller' => [['status' => 'pending_review', 'starts_at' => '-3 days', 'ends_at' => '-1 hour']],
            'cancelled' => [['status' => 'cancelled', 'starts_at' => '-3 days', 'ends_at' => '+1 day']],
        ];
    }

    /** @param array<string, mixed> $attrs */
    private function closedLot(array $attrs): Auction
    {
        foreach (['starts_at', 'ends_at'] as $k) {
            $attrs[$k] = now()->modify($attrs[$k]);
        }

        return $this->lot(array_merge(['title' => 'Closed Cabinet'], $attrs));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedLots')]
    public function test_the_lot_page_has_no_watch_button_on_a_closed_lot(array $attrs): void
    {
        $lot = $this->closedLot($attrs);

        $this->actingAs($this->person())->get(route('auctions.show', $lot))->assertOk()
            ->assertDontSee('Stop watching')
            ->assertDontSeeHtml('class="lot-watch"');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedLots')]
    public function test_browse_cards_have_no_watch_toggle_on_a_closed_lot_even_one_already_watched(array $attrs): void
    {
        $lot = $this->closedLot($attrs);
        $me = $this->person();
        Watchlist::withoutGlobalScopes()->create(['user_id' => $me->id, 'auction_id' => $lot->id]);

        $this->actingAs($me)->get(route('auctions.index'))->assertOk()
            ->assertSee('Closed Cabinet')
            ->assertDontSee('Stop watching');
    }

    public function test_browse_still_offers_watch_on_a_live_and_a_scheduled_lot(): void
    {
        $this->lot(['title' => 'Live Lamp']);
        $this->lot(['title' => 'Later Lamp', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);

        $page = $this->actingAs($this->person())->get(route('auctions.index'))->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), 'class="ds-lot__watch"'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedLots')]
    public function test_a_closed_lot_cannot_be_newly_watched_by_a_crafted_request(array $attrs): void
    {
        $lot = $this->closedLot($attrs);
        $me = $this->person();

        Livewire::actingAs($me)->test(WatchToggle::class, ['auctionId' => $lot->id, 'title' => 'x', 'watching' => false])->call('toggle')->assertSet('watching', false);
        Livewire::actingAs($me)->test(WatchlistButton::class, ['auction' => $lot])->call('toggle')->assertSet('watching', false);

        $this->assertSame(0, Watchlist::withoutGlobalScopes()->count());
    }

    public function test_a_watch_on_a_lot_that_has_since_closed_can_still_be_removed(): void
    {
        $lot = $this->closedLot(['status' => 'ended', 'starts_at' => '-3 days', 'ends_at' => '-1 day']);
        $me = $this->person();
        Watchlist::withoutGlobalScopes()->create(['user_id' => $me->id, 'auction_id' => $lot->id]);

        Livewire::actingAs($me)->test(WatchToggle::class, ['auctionId' => $lot->id, 'title' => 'x', 'watching' => true])->call('toggle')->assertSet('watching', false);
        $this->assertSame(0, Watchlist::withoutGlobalScopes()->count());

        // And the Watching page keeps its own remove control, so nobody is stuck with a closed lot in their list.
        Watchlist::withoutGlobalScopes()->create(['user_id' => $me->id, 'auction_id' => $lot->id]);
        $this->actingAs($me)->get(route('buyer.watchlist'))->assertOk()->assertSee('Stop watching');
    }

    // ---- a cancelled sale is told once, with no payment link

    public function test_a_cancelled_sale_is_told_once_and_offers_no_payment_link(): void
    {
        config(['auction.snipe_window_minutes' => 0]);
        $seller = $this->person();
        $leader = $this->person();
        $other = $this->person();
        $lot = $this->lot(['title' => 'Cancelled Sale Sofa'], $seller);
        app(BidPlacer::class)->place($lot, $other, '240.00');
        app(BidPlacer::class)->place($lot->fresh(), $leader, '260.00');
        $lot->forceFill(['ends_at' => now()->subMinute(), 'starts_at' => now()->subDays(2)])->save();
        app(AuctionSettler::class)->settle($lot->fresh());
        $settlement = AuctionSettlement::where('auction_id', $lot->id)->firstOrFail();
        $settlement->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        foreach ([$leader, $seller] as $who) {
            $html = $this->actingAs($who)->get(route('auctions.show', $lot))->assertOk()->getContent();
            $text = html_entity_decode(strip_tags($html));

            $this->assertSame(1, substr_count($text, 'Sale cancelled'), 'the cancelled sale is stated once');
            $this->assertStringNotContainsString('Open the payment page', $text);
            $this->assertStringNotContainsString('Pay now', $text);
            $this->assertStringContainsString('See the record of this sale', $text);
        }
    }
}
