<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\AuctionSettlement;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-42 W4: what the lot page says for every status and every kind of viewer. Each row is the page as one person sees it.
 * The words come from App\Support\LotOutcome; the live copy ("Current bid", "Place bid", "Reserve not met") must never leak
 * into a lot that is not live, and the reserve amount must never reach anyone but the seller.
 */
class LotPageOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private const RESERVE = '987.65';

    private User $seller;

    private User $leader;

    private User $other;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auction.snipe_window_minutes' => 0]);
        $this->seller = User::factory()->withPersonalTeam()->create();
        $this->leader = User::factory()->withPersonalTeam()->create();
        $this->other = User::factory()->withPersonalTeam()->create();
        $this->stranger = User::factory()->withPersonalTeam()->create();
    }

    private function lot(array $o = []): Auction
    {
        return Auction::factory()->create(array_merge(['seller_id' => $this->seller->id, 'title' => 'Signed guitar'], $o));
    }

    /** $other bids 240, $leader bids 260 (leader leads at R260.00). */
    private function withBids(Auction $a): Auction
    {
        app(BidPlacer::class)->place($a, $this->other, '240.00');
        app(BidPlacer::class)->place($a, $this->leader, '260.00');

        return $a->fresh();
    }

    private function close(Auction $a): Auction
    {
        $a->forceFill(['ends_at' => now()->subMinute(), 'starts_at' => now()->subDays(2)])->save();

        return $a->fresh();
    }

    private function settled(array $o = []): Auction
    {
        $a = $this->close($this->withBids($this->lot($o)));
        app(AuctionSettler::class)->settle($a);

        return $a->fresh();
    }

    private function page(Auction $a, User $who): string
    {
        $html = $this->actingAs($who)->get(route('auctions.show', $a))->assertOk()->getContent();

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    /** @param list<string> $see  @param list<string> $not */
    private function expectPage(Auction $a, User $who, array $see, array $not = []): void
    {
        $text = $this->page($a, $who);

        foreach ($see as $s) {
            $this->assertStringContainsString($s, $text, "expected the page to say: $s");
        }
        foreach ($not as $n) {
            $this->assertStringNotContainsString($n, $text, "the page must not say: $n");
        }
        $this->assertStringNotContainsString('987.65', $text);
        $this->assertStringNotContainsString('Bid placed successfully!', $text);
    }

    private const LIVE_ONLY = ['Place bid', 'Current bid', 'Closes in', 'You confirm on the next step'];

    // ---- scheduled

    public function test_scheduled(): void
    {
        $a = $this->lot(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4), 'reserve_price' => self::RESERVE]);

        $this->expectPage($a, $this->stranger, ['Scheduled', 'Not open yet', 'Bidding opens on', 'Starting bid', 'Opens in'],
            [...self::LIVE_ONLY, 'Reserve not met', 'Reserve met', 'No bids yet. The first bid']);
        $this->expectPage($a, $this->seller, ['Your lot opens on', 'Edit lot'], [...self::LIVE_ONLY, 'Reserve not met']);
    }

    // ---- live

    public function test_live_without_bids_for_a_visitor(): void
    {
        $a = $this->lot(['reserve_price' => self::RESERVE]);

        $this->expectPage($a, $this->stranger, ['Live', 'Starting bid', 'No bids yet', 'Place bid', 'Reserve not met', 'Opening bid is at least R220.00', 'Watch Signed guitar'],
            ['Current bid', 'You are leading', 'You were outbid']);
    }

    public function test_live_leader_other_bidder_and_seller(): void
    {
        $a = $this->withBids($this->lot(['reserve_price' => '100.00']));

        $this->expectPage($a, $this->leader, ['You are leading', 'Your bid of R260.00 is the highest', 'The reserve is met'], ['Place bid', 'You were outbid']);
        $this->expectPage($a, $this->other, ['You were outbid', 'Your last bid was R240.00', 'bid at least R280.00, which is R40.00 more', 'Bid R280.00', 'Place bid'], ['You are leading']);
        $this->expectPage($a, $this->stranger, ['Current bid', '2 bids', 'Place bid'], ['You are leading', 'You were outbid']);
        $this->expectPage($a, $this->seller, ['This is your lot', 'You cannot bid on your own lot', '2 bids so far', 'Manage your lots'], ['Place bid', 'Watch Signed guitar']);
    }

    public function test_the_leader_on_a_lot_below_its_reserve_is_told_what_to_expect(): void
    {
        $a = $this->withBids($this->lot(['reserve_price' => self::RESERVE]));

        $this->expectPage($a, $this->leader, ['You are leading', 'The reserve is not met. If the lot closes below it, the seller decides whether to accept the highest bid.']);
    }

    public function test_ending_soon_and_extended(): void
    {
        $a = $this->lot(['ends_at' => now()->addMinutes(20), 'snipe_extensions' => 2]);

        $this->expectPage($a, $this->stranger, ['Closing soon', 'Extended 2 times', 'Closing time extended 2 times by late bids']);
    }

    public function test_buy_now_is_offered_only_while_it_is_real(): void
    {
        $a = $this->lot(['buy_now_price' => '500.00']);
        $this->expectPage($a, $this->stranger, ['Buy now for R500.00', 'Skip the bidding']);
        $this->expectPage($a, $this->seller, [], ['Buy now for R500.00', 'Skip the bidding']);

        app(BidPlacer::class)->place($a, $this->other, '500.00');
        $this->expectPage($a->fresh(), $this->stranger, [], ['Skip the bidding']);
    }

    // ---- closed, result not recorded yet

    public function test_closed_but_not_yet_settled_does_not_claim_a_result(): void
    {
        $a = $this->close($this->withBids($this->lot(['starts_at' => now()->subDays(2)])));

        $this->expectPage($a, $this->stranger, ['Bidding has closed', 'The result is being recorded'], ['Sold', 'You won', 'Place bid']);
        $this->expectPage($a, $this->leader, ['Bidding has closed', 'You held the highest bid, R260.00'], ['You won this lot', 'Place bid']);
    }

    // ---- sold

    public function test_sold_for_every_viewer(): void
    {
        $a = $this->settled();
        $not = [...self::LIVE_ONLY, 'You are leading', 'Reserve not met'];

        $this->expectPage($a, $this->leader, ['Sold', 'Sold for', 'You won this lot', 'You won it for R260.00', 'Where payment stands is below', 'Payment pending'], $not);
        $this->expectPage($a, $this->other, ['You did not win this lot', 'It sold for R260.00', 'Your highest bid was R240.00'], [...$not, 'You won this lot', 'Payment pending']);
        $this->expectPage($a, $this->seller, ['Sold for R260.00 to', 'Payment is tracked below', 'Payment pending'], $not);
        $this->expectPage($a, $this->stranger, ['Sold for R260.00', 'after 2 bids'], [...$not, 'You won', 'Payment pending']);
    }

    public function test_bought_with_buy_now(): void
    {
        $a = $this->lot(['buy_now_price' => '500.00']);
        app(BidPlacer::class)->place($a, $this->other, '240.00');
        app(BuyNowPurchaser::class)->buy($a, $this->leader);
        $a = $a->fresh();

        $this->expectPage($a, $this->leader, ['You bought this lot with Buy Now', 'You paid the Buy Now price of R500.00']);
        $this->expectPage($a, $this->other, ['You did not win this lot', 'Another bidder bought it with Buy Now for R500.00']);
        $this->expectPage($a, $this->seller, ['Sold with Buy Now']);
        $this->expectPage($a, $this->stranger, ['Sold for R500.00 on']);
    }

    public function test_a_sale_the_seller_cancelled_is_not_a_win(): void
    {
        $a = $this->settled();
        AuctionSettlement::query()->where('auction_id', $a->id)->update(['status' => 'cancelled']);

        $this->expectPage($a, $this->leader, ['Sale cancelled', 'then the seller cancelled the sale. Nothing is due'], ['You won this lot', 'Pay now']);
    }

    // ---- ended below the reserve

    public function test_awaiting_the_sellers_decision_for_every_viewer(): void
    {
        $a = $this->close($this->withBids($this->lot(['reserve_price' => self::RESERVE, 'starts_at' => now()->subDays(2)])));
        app(AuctionSettler::class)->settle($a);
        $a = $a->fresh();
        $this->assertSame('pending_review', $a->status);
        $not = [...self::LIVE_ONLY, 'Cancelled', 'Reserve not met', 'Sold for'];

        $this->expectPage($a, $this->leader, ['Awaiting seller decision', 'The seller is deciding', 'Your bid of R260.00 was the highest, but it is below the reserve', 'Waiting since', 'There is no deadline for the decision'], $not);
        $this->expectPage($a, $this->other, ['The seller is deciding', 'Waiting since'], $not);
        $this->expectPage($a, $this->stranger, ['The seller is deciding', 'The highest bid, R260.00, is below the reserve'], $not);

        $text = $this->page($a, $this->seller); // the seller sees their own reserve, in the decision panel only
        $this->assertStringContainsString('This lot ended below your reserve', $text);
        $this->assertStringContainsString('Accept R260.00', $text);
        $this->assertStringContainsString('Reject the bid', $text);
        $this->assertStringContainsString('R987.65', $text);
    }

    public function test_after_the_seller_rejects_the_lot_is_not_sold_for_anyone(): void
    {
        $a = $this->close($this->withBids($this->lot(['reserve_price' => self::RESERVE, 'starts_at' => now()->subDays(2)])));
        app(AuctionSettler::class)->settle($a);
        $proposal = ReserveNotMetProposal::query()->where('auction_id', $a->id)->sole();
        Livewire::actingAs($this->seller)->test(ReserveDecisionPanel::class, ['proposal' => $proposal])->call('reject');
        $a = $a->fresh();

        $not = [...self::LIVE_ONLY, 'Sold for', 'You won', 'Payment pending'];
        $this->expectPage($a, $this->leader, ['Not sold', 'The seller did not accept your bid', 'No sale was made, so nothing is due'], $not);
        $this->expectPage($a, $this->stranger, ['Not sold', 'was below the reserve and the seller chose not to accept it'], $not);
        $this->expectPage($a, $this->seller, ['You ended this lot without a sale'], $not);
    }

    // ---- ended without a sale, cancelled, draft

    public function test_ended_with_no_bids(): void
    {
        $a = $this->close($this->lot(['starts_at' => now()->subDays(2)]));
        app(AuctionSettler::class)->settle($a);

        $this->expectPage($a->fresh(), $this->stranger, ['Not sold', 'Nobody bid on this lot, so it ended without a sale'], [...self::LIVE_ONLY, 'Sold for']);
    }

    public function test_cancelled(): void
    {
        $a = $this->lot(['status' => 'cancelled']);

        $this->expectPage($a, $this->stranger, ['Cancelled', 'The seller cancelled this lot. No sale was made, so nothing is due'], [...self::LIVE_ONLY, 'Watch Signed guitar']);
        $this->expectPage($a, $this->seller, ['You cancelled this lot'], self::LIVE_ONLY);
    }

    public function test_a_draft_is_for_its_seller_alone(): void
    {
        $a = $this->lot(['status' => 'draft', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);

        $this->expectPage($a, $this->seller, ['Draft', 'This lot is a draft', 'Only you can see it', 'Edit draft'], [...self::LIVE_ONLY]);
        $this->actingAs($this->stranger)->get(route('auctions.show', $a))->assertNotFound();
    }

    // ---- page furniture

    public function test_title_heading_landmarks_and_photo_fallback(): void
    {
        $a = $this->lot();
        $html = $this->actingAs($this->stranger)->get(route('auctions.show', $a))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Signed guitar | Dot.Auction</title>', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Signed guitar\s*<\/h1>/', $html);
        $this->assertStringContainsString('The seller has not added photos to this lot.', $html);
        $this->assertStringContainsString('No photo yet', $html);
        $lot = substr($html, (int) strpos($html, 'class="lot-wrap"'), (int) strpos($html, '</main>') - (int) strpos($html, 'class="lot-wrap"'));
        // The only inline style in the lot markup is the rail's custom property (--p, --o): data for the kit's closing rail, not decoration.
        $this->assertDoesNotMatchRegularExpression('/style="(?!--[po]: )/', $lot);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|\bon(click|change|submit)=/', $lot);
    }

    public function test_the_gallery_is_labelled_sized_lazy_and_served_by_the_private_route(): void
    {
        $a = $this->lot();
        foreach ([1, 2, 3] as $n) {
            AuctionImage::query()->create(['auction_id' => $a->id, 'path' => "x/$n.png", 'original_name' => "$n.png", 'mime' => 'image/png', 'size_bytes' => 10, 'width' => 1280, 'height' => 960, 'position' => $n]);
        }

        $html = $this->actingAs($this->stranger)->get(route('auctions.show', $a))->assertOk()->getContent();

        $this->assertStringContainsString('alt="Signed guitar, photo 2 of 3"', $html);
        $this->assertStringContainsString('width="1280" height="960"', $html);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'), 'only the first photo is eager');
        $this->assertStringContainsString('Show photo 3 of 3', $html);
        $this->assertStringContainsString('Previous photo', $html);
        $this->assertStringContainsString('/auctions/'.$a->id.'/images/', $html, 'photos come through the policy-checked route, never a storage path');
        $this->assertStringNotContainsString('x/1.png', $html);
    }

    public function test_reserve_decision_panel_asks_before_it_decides(): void
    {
        $a = $this->close($this->withBids($this->lot(['reserve_price' => self::RESERVE, 'starts_at' => now()->subDays(2)])));
        app(AuctionSettler::class)->settle($a);
        $proposal = ReserveNotMetProposal::query()->where('auction_id', $a->id)->sole();

        $panel = Livewire::actingAs($this->seller)->test(ReserveDecisionPanel::class, ['proposal' => $proposal]);
        $panel->assertSee('Accept R260.00 from')->assertSee('End this lot without a sale?')->assertSee('This cannot be undone');
        $this->assertSame('pending', $proposal->fresh()->status, 'showing the dialogs decides nothing');

        $panel->call('accept')->assertDispatched('reserve-decided', auctionId: $a->id)->assertSee('You accepted the highest bid')->assertSee('sold for R260.00');
        $this->assertSame('accepted', $proposal->fresh()->status);

        Livewire::actingAs($this->stranger)->test(ReserveDecisionPanel::class, ['proposal' => $proposal])->call('reject')->assertForbidden();
    }
}
