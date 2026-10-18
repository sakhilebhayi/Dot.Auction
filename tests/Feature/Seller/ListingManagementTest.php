<?php

namespace Tests\Feature\Seller;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\Bid;
use App\Models\User;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use App\Services\Auctions\ListingManager;
use App\Services\Auctions\ListingRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingManagementTest extends TestCase
{
    use RefreshDatabase;

    private AuctionCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = AuctionCategory::factory()->create();
    }

    private function seller(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Signed first-edition atlas',
            'description' => 'Boxed, with provenance letter.',
            'category_id' => $this->category->id,
            'starting_price' => '100.00',
            'reserve_price' => '150.00',
            'buy_now_price' => '',
            'bid_increment' => '10.00',
            'starts_at' => '',
            'ends_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
        ], $overrides);
    }

    private function bidOn(Auction $auction): Bid
    {
        return app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');
    }

    // ---------------------------------------------------------------- create

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('seller.auctions.create'))->assertRedirect('/login');
        $this->post(route('seller.auctions.store'), $this->payload())->assertRedirect('/login');
    }

    public function test_the_create_form_has_labelled_fields(): void
    {
        $html = $this->actingAs($this->seller())
            ->get(route('seller.auctions.create'))
            ->assertOk()
            ->getContent();

        foreach (['title', 'description', 'category_id', 'starting_price', 'reserve_price', 'buy_now_price', 'bid_increment', 'starts_at', 'ends_at'] as $field) {
            $this->assertStringContainsString('for="'.$field.'"', $html, "missing <label for=\"{$field}\">");
            $this->assertStringContainsString('id="'.$field.'"', $html, "missing field id {$field}");
        }
    }

    public function test_a_seller_creates_a_draft_owned_by_themselves(): void
    {
        $seller = $this->seller();
        $other = User::factory()->create();

        $this->actingAs($seller)
            ->post(route('seller.auctions.store'), $this->payload([
                'starting_price' => '1234.50',
                'reserve_price' => '2000.00',
                'seller_id' => $other->id,
                'status' => 'active',
                'current_price' => '9999',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $auction = Auction::sole();
        $this->assertSame($seller->id, $auction->seller_id);
        $this->assertSame('draft', $auction->status);
        $this->assertSame(123450, Auction::toCents($auction->starting_price));
        $this->assertSame(123450, Auction::toCents($auction->current_price));
        $this->assertSame(200000, Auction::toCents($auction->reserve_price));
        $this->assertNull($auction->buy_now_price);
    }

    public function test_one_decimal_place_is_accepted_and_stored_exactly(): void
    {
        $this->actingAs($this->seller())
            ->post(route('seller.auctions.store'), $this->payload(['starting_price' => '10.5', 'reserve_price' => '', 'bid_increment' => '0.1']))
            ->assertSessionHasNoErrors();

        $auction = Auction::sole();
        $this->assertSame(1050, Auction::toCents($auction->starting_price));
        $this->assertSame(10, Auction::toCents($auction->bid_increment));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidListings(): array
    {
        return [
            'title required' => [['title' => ''], 'title'],
            'starting price zero' => [['starting_price' => '0'], 'starting_price'],
            'starting price negative' => [['starting_price' => '-5'], 'starting_price'],
            'starting price not a number' => [['starting_price' => 'abc'], 'starting_price'],
            'starting price exponent' => [['starting_price' => '1e3'], 'starting_price'],
            'starting price three decimals' => [['starting_price' => '10.999'], 'starting_price'],
            'starting price thousands separator' => [['starting_price' => '1,000.00'], 'starting_price'],
            'starting price absurdly large' => [['starting_price' => '99999999999'], 'starting_price'],
            'reserve below start' => [['reserve_price' => '99.99'], 'reserve_price'],
            'reserve three decimals' => [['reserve_price' => '150.001'], 'reserve_price'],
            'increment zero' => [['bid_increment' => '0'], 'bid_increment'],
            'increment negative' => [['bid_increment' => '-1'], 'bid_increment'],
            'increment missing' => [['bid_increment' => ''], 'bid_increment'],
            'buy now not above start' => [['buy_now_price' => '100.00', 'reserve_price' => ''], 'buy_now_price'],
            'buy now below reserve' => [['buy_now_price' => '140.00', 'reserve_price' => '150.00'], 'buy_now_price'],
            'ends in the past' => [['ends_at' => '2020-01-01T10:00'], 'ends_at'],
            'ends too soon' => [['ends_at' => '__SOON__'], 'ends_at'],
            'runs too long' => [['ends_at' => '__LONG__'], 'ends_at'],
            'ends before it starts' => [['starts_at' => '__LATE_START__'], 'ends_at'],
            'category missing' => [['category_id' => ''], 'category_id'],
            'category does not exist' => [['category_id' => 99999], 'category_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidListings')]
    public function test_invalid_listings_are_rejected_with_a_field_error(array $override, string $field): void
    {
        $override = array_map(fn ($value) => match ($value) {
            '__SOON__' => now()->addMinutes(20)->format('Y-m-d\TH:i'),
            '__LONG__' => now()->addDays(90)->format('Y-m-d\TH:i'),
            '__LATE_START__' => now()->addDays(5)->format('Y-m-d\TH:i'),
            default => $value,
        }, $override);

        $this->actingAs($this->seller())
            ->from(route('seller.auctions.create'))
            ->post(route('seller.auctions.store'), $this->payload($override))
            ->assertSessionHasErrors($field)
            ->assertRedirect(route('seller.auctions.create'));

        $this->assertSame(0, Auction::count());
    }

    public function test_the_form_reports_errors_next_to_the_field_and_in_a_summary(): void
    {
        $seller = $this->seller();

        $html = $this->actingAs($seller)
            ->followingRedirects()
            ->from(route('seller.auctions.create'))
            ->post(route('seller.auctions.store'), $this->payload(['starting_price' => '0']))
            ->getContent();

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('id="starting_price-error"', $html);
        $this->assertStringContainsString('aria-describedby', $html);
    }

    // ------------------------------------------------------------------ edit

    public function test_a_draft_can_be_fully_edited(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)
            ->put(route('seller.auctions.update', $auction), $this->payload([
                'title' => 'Renamed',
                'starting_price' => '300.00',
                'reserve_price' => '400.00',
                'buy_now_price' => '900.00',
                'bid_increment' => '25.00',
            ]))
            ->assertSessionHasNoErrors();

        $auction->refresh();
        $this->assertSame('Renamed', $auction->title);
        $this->assertSame(30000, Auction::toCents($auction->starting_price));
        $this->assertSame(30000, Auction::toCents($auction->current_price));
        $this->assertSame(40000, Auction::toCents($auction->reserve_price));
        $this->assertSame(90000, Auction::toCents($auction->buy_now_price));
        $this->assertSame('draft', $auction->status);
    }

    public function test_a_live_auction_with_no_bids_can_still_change_its_terms(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)
            ->put(route('seller.auctions.update', $auction), $this->payload(['starting_price' => '250.00', 'reserve_price' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(25000, Auction::toCents($auction->fresh()->current_price));
        $this->assertSame('active', $auction->fresh()->status);
    }

    public function test_after_a_bid_only_the_description_can_change(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create([
            'seller_id' => $seller->id,
            'title' => 'Original title',
            'reserve_price' => '210.00',
        ]);
        $this->bidOn($auction);
        $before = $auction->fresh();

        $this->actingAs($seller)
            ->put(route('seller.auctions.update', $auction), $this->payload([
                'description' => 'Updated description with extra photos.',
                'title' => 'Sneaky new title',
                'starting_price' => '1.00',
                'reserve_price' => '1.00',
                'buy_now_price' => '300.00',
                'bid_increment' => '1.00',
                'category_id' => '',
                'ends_at' => now()->addMinutes(90)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasNoErrors();

        $after = $auction->fresh();
        $this->assertSame('Updated description with extra photos.', $after->description);
        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->starting_price, $after->starting_price);
        $this->assertSame($before->reserve_price, $after->reserve_price);
        $this->assertSame($before->buy_now_price, $after->buy_now_price);
        $this->assertSame($before->bid_increment, $after->bid_increment);
        $this->assertSame($before->current_price, $after->current_price);
        $this->assertSame($before->category_id, $after->category_id);
        $this->assertTrue($before->ends_at->equalTo($after->ends_at));
    }

    public function test_the_edit_page_locks_the_commercial_terms_once_a_bid_exists(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);
        $this->bidOn($auction);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();

        $this->assertStringContainsString('id="description"', $html);
        $this->assertStringNotContainsString('id="starting_price"', $html);
        $this->assertStringNotContainsString('id="ends_at"', $html);
        $this->assertStringContainsString('a bid has been placed', strtolower($html));
    }

    public function test_the_service_refuses_a_terms_change_when_a_bid_landed_after_the_page_loaded(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);
        $stale = Auction::find($auction->id); // no bids when this was loaded
        $this->bidOn($auction);

        try {
            app(ListingManager::class)->update($stale, [
                'starting_price' => '1.00', 'bid_increment' => '1.00', 'description' => 'x',
                'title' => 'x', 'category_id' => $this->category->id, 'ends_at' => now()->addDays(2),
            ]);
            $this->fail('A terms change after a bid must be refused.');
        } catch (ListingRejected $e) {
            $this->assertStringContainsString('bid', strtolower($e->getMessage()));
        }

        $this->assertSame(20000, Auction::toCents($auction->fresh()->starting_price));
    }

    public function test_ended_and_cancelled_auctions_cannot_be_edited(): void
    {
        $seller = $this->seller();

        foreach (['ended', 'cancelled', 'pending_review'] as $status) {
            $auction = Auction::factory()->create(['seller_id' => $seller->id, 'status' => $status]);
            $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertForbidden();
            $this->actingAs($seller)->put(route('seller.auctions.update', $auction), $this->payload())->assertForbidden();
        }
    }

    // --------------------------------------------------------------- publish

    public function test_publishing_a_draft_opens_it_immediately_when_no_start_was_chosen(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create([
            'seller_id' => $seller->id,
            'starts_at' => now()->subHours(5),
            'ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($seller)->post(route('seller.auctions.publish', $auction))->assertSessionHasNoErrors();

        $auction->refresh();
        $this->assertSame('active', $auction->status);
        $this->assertTrue($auction->isActive());
        $this->assertTrue($auction->starts_at->greaterThan(now()->subMinute()), 'a stale start time must be moved to now');
    }

    public function test_publishing_with_a_future_start_schedules_the_auction(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create([
            'seller_id' => $seller->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(4),
        ]);

        $this->actingAs($seller)->post(route('seller.auctions.publish', $auction))->assertSessionHasNoErrors();

        $auction->refresh();
        $this->assertSame('active', $auction->status);
        $this->assertTrue($auction->isScheduled());
        $this->assertFalse($auction->isActive());

        $this->expectException(BidRejected::class);
        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');
    }

    public function test_a_draft_whose_end_time_has_passed_cannot_be_published(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create([
            'seller_id' => $seller->id,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subHour(),
        ]);

        $this->actingAs($seller)
            ->post(route('seller.auctions.publish', $auction))
            ->assertSessionHasErrors('publish');

        $this->assertSame('draft', $auction->fresh()->status);
    }

    public function test_only_drafts_can_be_published(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)
            ->post(route('seller.auctions.publish', $auction))
            ->assertSessionHasErrors('publish');
    }

    // ---------------------------------------------------------------- cancel

    public function test_an_auction_without_bids_can_be_cancelled(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)->post(route('seller.auctions.cancel', $auction))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $auction->fresh()->status);
    }

    public function test_an_auction_with_bids_cannot_be_cancelled(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);
        $this->bidOn($auction);

        $this->actingAs($seller)
            ->post(route('seller.auctions.cancel', $auction))
            ->assertSessionHasErrors('cancel');

        $this->assertSame('active', $auction->fresh()->status);
    }

    public function test_the_service_refuses_to_cancel_when_a_bid_landed_after_the_page_loaded(): void
    {
        $auction = Auction::factory()->create();
        $stale = Auction::find($auction->id);
        $this->bidOn($auction);

        $this->expectException(ListingRejected::class);

        try {
            app(ListingManager::class)->cancel($stale);
        } finally {
            $this->assertSame('active', $auction->fresh()->status);
        }
    }

    public function test_drafts_are_deleted_not_cancelled_and_live_auctions_cannot_be_deleted(): void
    {
        $seller = $this->seller();
        $draft = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        $live = Auction::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)->post(route('seller.auctions.cancel', $draft))->assertSessionHasErrors('cancel');
        $this->actingAs($seller)->delete(route('seller.auctions.destroy', $live))->assertSessionHasErrors('delete');
        $this->actingAs($seller)->delete(route('seller.auctions.destroy', $draft))->assertRedirect(route('seller.auctions.index'));

        $this->assertNull(Auction::find($draft->id));
        $this->assertNotNull(Auction::find($live->id));
    }

    // ---------------------------------------------------------- authorization

    public function test_only_the_seller_can_manage_an_auction(): void
    {
        $owner = $this->seller();
        $stranger = $this->seller();
        $auction = Auction::factory()->draft()->create(['seller_id' => $owner->id]);

        $this->actingAs($stranger);
        $this->get(route('seller.auctions.edit', $auction))->assertForbidden();
        $this->put(route('seller.auctions.update', $auction), $this->payload())->assertForbidden();
        $this->post(route('seller.auctions.publish', $auction))->assertForbidden();
        $this->post(route('seller.auctions.cancel', $auction))->assertForbidden();
        $this->delete(route('seller.auctions.destroy', $auction))->assertForbidden();

        $this->assertSame('draft', $auction->fresh()->status);
        $this->assertNotNull(Auction::find($auction->id));
    }

    public function test_a_seller_still_cannot_bid_on_their_own_auction(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);

        $this->expectException(BidRejected::class);
        app(BidPlacer::class)->place($auction, $seller, '220.00');
    }

    // ------------------------------------------------------------- listing UI

    public function test_the_seller_index_lists_only_their_own_auctions_with_real_actions(): void
    {
        $seller = $this->seller();
        $mine = Auction::factory()->draft()->create(['seller_id' => $seller->id, 'title' => 'My Draft Lot']);
        Auction::factory()->create(['title' => 'Someone Elses Lot']);

        $html = $this->actingAs($seller)
            ->get(route('seller.auctions.index'))
            ->assertOk()
            ->assertSee('My Draft Lot')
            ->assertDontSee('Someone Elses Lot')
            ->getContent();

        $this->assertStringContainsString(route('seller.auctions.edit', $mine), $html);
        $this->assertStringContainsString(route('seller.auctions.publish', $mine), $html);
    }

    public function test_the_seller_index_shows_scheduled_auctions_as_scheduled(): void
    {
        $seller = $this->seller();
        Auction::factory()->scheduled()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->assertSee('Scheduled');
    }

    public function test_the_dashboard_links_to_listing_creation_and_shows_my_auctions(): void
    {
        $seller = $this->seller();
        Auction::factory()->create(['seller_id' => $seller->id, 'title' => 'On My Dashboard']);

        $html = $this->actingAs($seller)->get('/dashboard')->assertOk()->assertSee('On My Dashboard')->getContent();

        $this->assertStringContainsString('href="'.route('seller.auctions.create').'"', $html);
        $this->assertStringNotContainsString('Seller listing management is coming soon', $html);
        $this->assertStringNotContainsString('List Auction &middot; coming soon', $html);
    }

    public function test_the_empty_dashboard_invites_the_seller_to_list_something(): void
    {
        $html = $this->actingAs($this->seller())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('You have not listed anything yet', $html);
        $this->assertStringNotContainsString('Seller listing tools are coming soon', $html);
    }

    public function test_the_dashboard_active_count_excludes_auctions_that_have_not_opened(): void
    {
        $seller = $this->seller();
        Auction::factory()->scheduled()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)->get('/dashboard')->assertOk()->assertViewHas('activeAuctions', 0);
    }
}
