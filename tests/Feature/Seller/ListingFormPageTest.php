<?php

namespace Tests\Feature\Seller;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionImage;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R-42 UI: the listing form on the kit. Times in the display zone, the plain-words terms from config, the error summary,
 * the locked-terms view, and the photo upload in both its plain and its JavaScript (JSON) form.
 */
class ListingFormPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('auction-images');
        $this->category = AuctionCategory::factory()->create();
    }

    private AuctionCategory $category;

    private function seller(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Brass telescope', 'description' => 'Works.', 'category_id' => $this->category->id,
            'starting_price' => '100.00', 'reserve_price' => '', 'buy_now_price' => '', 'bid_increment' => '10.00',
            'starts_at' => '', 'ends_at' => '2026-12-01T18:00',
        ], $overrides);
    }

    public function test_the_form_names_the_time_zone_and_suggests_a_closing_time_a_week_out(): void
    {
        $this->travelTo('2026-11-08 10:00:00'); // 12:00 in Johannesburg

        $html = $this->actingAs($this->seller())->get(route('seller.auctions.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Times are in South Africa time (SAST).', $html);
        $this->assertStringContainsString('value="2026-11-15T18:00"', $html, 'the suggestion is 18:00 local time, seven days out');
        $this->assertStringContainsString('type="datetime-local"', $html);
        $this->assertStringContainsString('<title>List a lot | ', $html);
    }

    public function test_a_closing_time_typed_in_local_time_is_stored_in_utc_and_shown_back_in_local_time(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)->post(route('seller.auctions.store'), $this->payload(['ends_at' => '2026-12-01T18:00', 'starts_at' => '2026-11-20T09:30']))->assertRedirect();

        $auction = Auction::sole();
        $this->assertSame('2026-12-01 16:00:00', $auction->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 07:30:00', $auction->starts_at->utc()->format('Y-m-d H:i:s'));

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();
        $this->assertStringContainsString('value="2026-12-01T18:00"', $html);
        $this->assertStringContainsString('value="2026-11-20T09:30"', $html);
    }

    public function test_the_terms_in_plain_words_come_from_config_not_from_the_view(): void
    {
        config(['auction.snipe_window_minutes' => 3, 'auction.snipe_extension_minutes' => 2, 'auction.snipe_max_extensions' => 4, 'auction.listing.max_duration_days' => 21, 'auction.currency_symbol' => 'N$']);

        $html = $this->actingAs($this->seller())->get(route('seller.auctions.create'))->assertOk()->getContent();

        $this->assertStringContainsString('A bid in the final 3 minutes moves the close 2 minutes later, up to 4 times', $html);
        $this->assertStringContainsString('at most 21 days after it opens', $html);
        $this->assertStringContainsString('>N$</span>', $html, 'the money fields carry the configured symbol');
        $this->assertStringContainsString('Confidential. Buyers see only whether it has been met, never the amount.', $html);
        $this->assertStringContainsString('data-pick-days="14"', $html);
        $this->assertStringNotContainsString('data-pick-days="30"', $html);

        config(['auction.snipe_window_minutes' => 0]);
        $off = $this->actingAs($this->seller())->get(route('seller.auctions.create'))->getContent();
        $this->assertStringNotContainsString('A bid in the final', $off, 'when anti-snipe is off the page says nothing about it');
    }

    public function test_fields_with_a_limit_say_so_and_the_save_button_says_what_happens(): void
    {
        $html = $this->actingAs($this->seller())->get(route('seller.auctions.create'))->assertOk()->getContent();

        $this->assertStringContainsString('maxlength="120"', $html);
        $this->assertStringContainsString('maxlength="5000"', $html);
        $this->assertStringContainsString('data-count', $html);
        $this->assertStringContainsString('Save draft and add photos', $html);
        $this->assertStringContainsString('Choose a category', $html);
    }

    public function test_errors_come_with_a_focusable_summary_and_jump_links(): void
    {
        $html = $this->actingAs($this->seller())->followingRedirects()->from(route('seller.auctions.create'))
            ->post(route('seller.auctions.store'), $this->payload(['title' => '', 'starting_price' => '0']))->getContent();

        $this->assertStringContainsString('id="error-summary"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('data-autofocus', $html);
        $this->assertStringContainsString('There are 2 problems to fix', $html);
        $this->assertStringContainsString('href="#title" data-jump', $html);
        $this->assertStringContainsString('href="#starting_price" data-jump', $html);
        $this->assertStringContainsString('id="title-error"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
    }

    public function test_once_a_bid_exists_the_terms_are_a_read_only_ledger(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->create(['seller_id' => $seller->id, 'title' => 'Locked Lot', 'reserve_price' => '300.00']);
        Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => User::factory()->create()->id, 'amount' => '220.00', 'is_winning' => true]);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->assertSee('The terms are locked')->assertSee('Locked terms')->getContent();

        $this->assertStringNotContainsString('id="title"', $html);
        $this->assertStringNotContainsString('id="starting_price"', $html);
        $this->assertStringContainsString('id="description"', $html);
        $this->assertStringContainsString('R300.00', strip_tags($html), 'the seller, and only the seller, sees the reserve');
        $this->assertStringContainsString('It has bids, so it can no longer be cancelled.', $html);
    }

    public function test_publish_and_delete_ask_before_they_act_and_say_so_in_words(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create(['seller_id' => $seller->id, 'title' => 'My Draft']);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();

        $this->assertStringContainsString('Publish “My Draft”?', $html);
        $this->assertStringContainsString('Once someone bids, the price, reserve and closing time are locked.', $html);
        $this->assertStringContainsString('Delete this draft?', $html);
        $this->assertStringContainsString('This cannot be undone.', $html);
        $this->assertStringContainsString('data-unsaved', $html);
        $this->assertStringContainsString('<title>Edit My Draft | ', $html);
    }

    public function test_the_photo_area_states_the_limit_the_size_and_the_cover_rule(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        AuctionImage::create(['auction_id' => $auction->id, 'path' => '1/a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 4, 'height' => 3, 'position' => 0]);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();

        $this->assertStringContainsString('Up to 6 photos. The first one is the cover', $html);
        $this->assertStringContainsString('1 of 6', $html);
        $this->assertStringContainsString('up to 4 MB each. You can add 5 more.', $html);
        $this->assertStringContainsString('data-max-bytes="'.(4096 * 1024).'"', $html);
        $this->assertStringContainsString('Cover', $html);
        $this->assertMatchesRegularExpression('/<input id="images"[^>]*type="file"[^>]*multiple/', $html);
        $this->assertStringContainsString('aria-describedby="images-hint', $html);
    }

    public function test_the_photo_drop_zone_gets_json_for_success_and_for_every_kind_of_failure(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        $post = fn (array $files) => $this->actingAs($seller)->postJson(route('seller.auctions.images.store', $auction), ['images' => $files]);

        $post([UploadedFile::fake()->image('a.jpg', 40, 30), UploadedFile::fake()->image('b.png', 40, 30)])
            ->assertOk()->assertJson(['added' => 2, 'message' => '2 photos added.']);
        $this->assertSame(2, $auction->images()->count());
        $this->assertSame('2 photos added.', session('status'));

        $post([UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('images.0');

        $post([])->assertStatus(422)->assertJsonValidationErrors('images');

        // Over the six-photo limit across requests: the service refuses, and the answer is JSON too.
        $post(array_map(fn ($i) => UploadedFile::fake()->image("c$i.jpg", 40, 30), range(1, 5)))
            ->assertStatus(422)->assertJsonPath('errors.images.0', 'An auction can have at most 6 photos. This one has 2, so you can add 4 more.');
        $this->assertSame(2, $auction->images()->count());

        // A stranger is refused the same way as before.
        $this->actingAs($this->seller())->postJson(route('seller.auctions.images.store', $auction), ['images' => [UploadedFile::fake()->image('x.jpg', 40, 30)]])->assertForbidden();
    }

    public function test_the_plain_form_post_still_redirects_with_a_message(): void
    {
        $seller = $this->seller();
        $auction = Auction::factory()->draft()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller)->post(route('seller.auctions.images.store', $auction), ['images' => [UploadedFile::fake()->image('a.jpg', 40, 30)]])
            ->assertRedirect(route('seller.auctions.edit', $auction))->assertSessionHas('status', '1 photo added.');
    }

    public function test_a_platform_with_no_categories_says_so_instead_of_offering_an_empty_select(): void
    {
        AuctionCategory::query()->delete();

        $html = $this->actingAs($this->seller())->get(route('seller.auctions.create'))->assertOk()
            ->assertSee('No categories exist yet')
            ->assertSee('Categories are added by whoever runs Dot.Auction')
            ->getContent();

        $this->assertStringNotContainsString('id="category_id"', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*disabled|<button[^>]*disabled[^>]*type="submit"/', $html);

        // With a category the form is the normal one.
        AuctionCategory::factory()->create(['name' => 'Things']);
        $this->actingAs($this->seller())->get(route('seller.auctions.create'))->assertOk()->assertSee('id="category_id"', false)->assertDontSee('No categories exist yet');
    }
}
