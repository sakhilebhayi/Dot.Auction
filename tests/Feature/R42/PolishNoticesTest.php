<?php

namespace Tests\Feature\R42;

use App\Models\Auction;
use App\Models\User;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Design jury 1, P1: eight near-identical outbid notices for one lot, one of them for a lot that was bought with Buy Now;
 * a lot whose time is up showing "Not sold" before any result exists.
 */
class PolishNoticesTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    private function outbidRows(User $user, ?Auction $lot = null)
    {
        return $user->notifications()->where('type', OutbidNotification::class)
            ->get()->filter(fn ($n) => $lot === null || (int) $n->data['auction_id'] === $lot->id);
    }

    public function test_a_bidding_war_on_one_lot_is_one_unread_notice_with_the_newest_price(): void
    {
        $lot = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $me = $this->person('Me');
        $b = $this->person('B');
        $c = $this->person('C');
        $placer = app(BidPlacer::class);

        $placer->place($lot, $me, '110.00');
        $placer->place($lot, $b, '120.00');
        $this->assertCount(1, $this->outbidRows($me, $lot));

        $placer->place($lot, $me, '130.00');
        $placer->place($lot, $c, '140.00');
        $placer->place($lot, $me, '150.00');
        $placer->place($lot, $b, '160.00');

        $rows = $this->outbidRows($me, $lot);
        $this->assertCount(1, $rows, 'one unread outbid notice per lot');
        $this->assertStringContainsString('R160.00', $rows->first()->data['message'], 'it carries the newest price');
        $this->assertNull($rows->first()->read_at);
    }

    public function test_a_read_notice_is_not_rewritten_and_a_second_lot_gets_its_own(): void
    {
        $one = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $two = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $me = $this->person('Me');
        $other = $this->person('Other');
        $placer = app(BidPlacer::class);

        $placer->place($one, $me, '110.00');
        $placer->place($one, $other, '120.00');
        $this->outbidRows($me, $one)->first()->markAsRead();
        $placer->place($one, $me, '130.00');
        $placer->place($one, $other, '140.00');
        $placer->place($two, $me, '110.00');
        $placer->place($two, $other, '120.00');

        $this->assertCount(2, $this->outbidRows($me, $one), 'a read notice stays as history and a new unread one starts');
        $this->assertCount(1, $this->outbidRows($me, $two));
        $this->assertSame(2, $me->unreadNotifications()->count());
    }

    public function test_opening_the_lot_marks_its_outbid_notice_read(): void
    {
        $lot = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $other = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $me = $this->person('Me');
        $rival = $this->person('Rival');
        $placer = app(BidPlacer::class);
        foreach ([$lot, $other] as $l) {
            $placer->place($l, $me, '110.00');
            $placer->place($l, $rival, '120.00');
        }
        $this->assertSame(2, $me->unreadNotifications()->count());

        $this->actingAs($me)->get(route('auctions.show', $lot))->assertOk();

        $this->assertSame(1, $me->fresh()->unreadNotifications()->count());
        $this->assertNotNull($this->outbidRows($me, $lot)->first()->read_at);
        $this->assertNull($this->outbidRows($me, $other)->first()->read_at, 'another lot is untouched');
    }

    public function test_a_buy_now_sale_never_says_outbid(): void
    {
        $lot = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00', 'buy_now_price' => '500.00']);
        $leader = $this->person('Leader');
        $buyer = $this->person('Buyer');
        app(BidPlacer::class)->place($lot, $leader, '120.00');

        app(BuyNowPurchaser::class)->buy($lot, $buyer);

        $notice = $this->outbidRows($leader, $lot)->first();
        $this->assertNotNull($notice);
        $this->assertSame('Sold to another bidder by Buy Now', $notice->data['title']);
        $this->assertStringContainsStringIgnoringCase('buy now', $notice->data['message']);
        $this->assertStringNotContainsStringIgnoringCase('outbid', $notice->data['title'].' '.$notice->data['message']);
        $this->assertStringNotContainsString('higher', $notice->data['message']);
    }

    public function test_buy_now_over_an_unread_outbid_notice_rewrites_it_in_place(): void
    {
        $lot = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00', 'buy_now_price' => '500.00']);
        $me = $this->person('Me');
        $rival = $this->person('Rival');
        $buyer = $this->person('Buyer');
        $placer = app(BidPlacer::class);
        $placer->place($lot, $me, '110.00');
        $placer->place($lot, $rival, '120.00');
        $placer->place($lot, $me, '130.00');

        app(BuyNowPurchaser::class)->buy($lot, $buyer);

        $rows = $this->outbidRows($me, $lot);
        $this->assertCount(1, $rows);
        $this->assertSame('Sold to another bidder by Buy Now', $rows->first()->data['title']);
    }

    public function test_a_lot_whose_time_is_up_but_has_no_result_yet_is_closed_result_pending_not_not_sold(): void
    {
        $leader = $this->person('Leader');
        $lot = $this->lot(['title' => 'Rug', 'starting_price' => '100.00', 'current_price' => '150.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]);
        $this->bid($lot, $leader, '150.00');

        $page = $this->actingAs($leader)->get(route('auctions.show', $lot))->assertOk();
        $page->assertSee('Closed, result pending')->assertSee('The result is being recorded and will appear here within a few minutes')->assertDontSeeHtml('data-state="unsold"');

        $browse = $this->actingAs($leader)->get(route('auctions.index'))->assertOk();
        $browse->assertSeeHtml('data-state="closing"')->assertDontSeeHtml('data-state="unsold"');

        $this->actingAs($leader)->get(route('buyer.bids'))->assertOk()->assertDontSeeHtml('data-state="unsold"');
    }
}
