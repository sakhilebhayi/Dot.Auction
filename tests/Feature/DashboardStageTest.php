<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Home's master-detail pair (R-51): the tables of the person's own lots and the stage that shows the selected one. The selection is `?lot=<id>`,
 * which may only ever name a row the person already owns on this page.
 */
class DashboardStageTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    private function stageHtml(string $html): string
    {
        $this->assertSame(1, preg_match('#<aside class="stage".*?</aside>#s', $html, $m), 'The stage is missing.');

        return $m[0];
    }

    /** @return array{0: User, 1: Auction, 2: Auction} a buyer with two open bids: the first closes sooner */
    private function buyerWithTwoBids(): array
    {
        $buyer = $this->person('Sipho Nkosi');
        $soon = $this->lot(['title' => 'Soon Lot', 'ends_at' => now()->addHours(3)]);
        $later = $this->lot(['title' => 'Later Lot', 'ends_at' => now()->addDays(2)]);
        $this->bid($soon, $this->person('Rival'), '300.00');
        $this->bid($soon, $buyer, '310.00');
        $this->bid($later, $buyer, '120.00');

        return [$buyer, $soon, $later];
    }

    public function test_the_stage_defaults_to_the_first_row_and_follows_a_valid_selection(): void
    {
        [$buyer, $soon, $later] = $this->buyerWithTwoBids();

        $default = $this->actingAs($buyer)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Soon Lot', $this->stageHtml($default));
        $this->assertSame(1, substr_count($default, 'aria-current="true"'));

        $picked = $this->actingAs($buyer)->get('/dashboard?lot='.$later->id)->assertOk()->getContent();
        $this->assertStringContainsString('Later Lot', $this->stageHtml($picked));
        $this->assertStringNotContainsString('Soon Lot', $this->stageHtml($picked));
        $this->assertSame(1, preg_match('#<tr\s+aria-current="true"[^>]*>#', $picked, $row));
        $this->assertStringContainsString('data-title="Later Lot"', $row[0]);
        // Every row links to its own selection.
        $this->assertStringContainsString('href="'.route('dashboard', ['lot' => $later->id]).'#stage"', $picked);
    }

    public function test_a_lot_that_is_not_one_of_my_own_rows_is_ignored(): void
    {
        [$buyer] = $this->buyerWithTwoBids();
        $stranger = $this->lot(['title' => 'Strangers Lot', 'ends_at' => now()->addHours(1)]);
        $this->bid($stranger, $this->person('Someone Else'), '90.00');
        $unbidOpen = $this->lot(['title' => 'Open But Not Mine', 'ends_at' => now()->addHours(2)]);

        foreach ([(string) $stranger->id, (string) $unbidOpen->id, '999999', 'abc', '1e3', '-1', '1.5', '99999999999999999999', ''] as $bad) {
            $html = $this->actingAs($buyer)->get('/dashboard?lot='.$bad)->assertOk()->getContent();
            $stage = $this->stageHtml($html);
            $this->assertStringContainsString('Soon Lot', $stage, "lot={$bad} must fall back to the default");
            $this->assertStringNotContainsString('Strangers Lot', $stage);
            $this->assertStringNotContainsString('Open But Not Mine', $stage);
        }

        // An array parameter is not a lot id either.
        $this->actingAs($buyer)->get('/dashboard?lot[]='.$stranger->id)->assertOk();
    }

    public function test_a_buyer_cannot_select_another_persons_row_and_a_seller_selects_only_their_own_open_lots(): void
    {
        $seller = $this->person('Thandi Mokoena');
        $mine = $this->lot(['title' => 'My Rug', 'ends_at' => now()->addHours(4)], $seller);
        $draft = $this->lot(['title' => 'My Draft', 'status' => 'draft'], $seller);
        $theirs = $this->lot(['title' => 'Their Rug', 'ends_at' => now()->addHours(1)]);

        $own = $this->actingAs($seller)->get('/dashboard?lot='.$mine->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-stage="selling"', $this->stageHtml($own));
        $this->assertStringContainsString('My Rug', $this->stageHtml($own));

        foreach ([$theirs->id, $draft->id] as $id) {
            $html = $this->actingAs($seller)->get('/dashboard?lot='.$id)->assertOk()->getContent();
            $this->assertStringContainsString('My Rug', $this->stageHtml($html));
            $this->assertStringNotContainsString('Their Rug', $this->stageHtml($html));
            $this->assertStringNotContainsString('My Draft', $this->stageHtml($html));
        }
    }

    public function test_one_account_with_bids_and_lots_gets_both_tables_and_the_stage_follows_either(): void
    {
        $user = $this->person('Both Ways');
        $mine = $this->lot(['title' => 'Own Lamp', 'ends_at' => now()->addDays(3)], $user);
        $theirs = $this->lot(['title' => 'Their Chair', 'ends_at' => now()->addDays(2)]);
        $this->bid($theirs, $user, '75.00');

        $html = $this->actingAs($user)->get('/dashboard?lot='.$mine->id)->assertOk()->getContent();

        $this->assertStringContainsString('id="my-bids"', $html);
        $this->assertStringContainsString('id="selling"', $html);
        $this->assertStringContainsString('Own Lamp', $this->stageHtml($html));
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
    }

    public function test_the_primary_action_is_still_one_and_only_one_and_the_reserve_accept_is_outlined(): void
    {
        $seller = $this->person('Thandi');
        $this->awaitingDecision($seller, $this->person('Bidder'));
        $this->lot(['ends_at' => now()->addHours(2)], $seller);

        $html = $this->actingAs($seller)->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('#\sdata-primary-action[\s=>]#', $html));
        $this->assertSame(1, preg_match('#<a href="[^"]+" class="[^"]*ds-btn--primary[^"]*" data-primary-action#', $html));
        // The irreversible acceptance is an outlined button; the page's gold fill stays with the one primary action.
        $this->assertMatchesRegularExpression('#class="[^"]*ds-btn--quiet[^"]*lot-decide__accept[^"]*"[^>]*data-dialog-open="accept-bid-#', $html);
        $this->assertDoesNotMatchRegularExpression('#ds-btn--primary[^>]*data-dialog-open="accept-bid-#', $html);
    }

    public function test_a_lot_with_a_photo_shows_it_in_its_row_and_on_the_stage_and_a_lot_without_one_says_so(): void
    {
        [$buyer, $soon, $later] = $this->buyerWithTwoBids();
        $image = AuctionImage::create(['auction_id' => $soon->id, 'path' => 'x/soon.jpg', 'original_name' => 'soon.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'width' => 640, 'height' => 480, 'position' => 0]);

        $html = $this->actingAs($buyer)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('src="'.$image->url('thumb').'"', $html);
        $this->assertStringContainsString('src="'.$image->url('medium').'"', $this->stageHtml($html));
        $this->assertStringContainsString('alt="Photo of Soon Lot"', $this->stageHtml($html));

        $without = $this->actingAs($buyer)->get('/dashboard?lot='.$later->id)->assertOk()->getContent();
        $this->assertStringContainsString('No photo yet', $this->stageHtml($without));
    }

    public function test_figures_on_the_stage_are_the_stored_ones_and_the_clock_is_the_real_countdown(): void
    {
        [$buyer, $soon] = $this->buyerWithTwoBids();

        $stage = $this->stageHtml($this->actingAs($buyer)->get('/dashboard')->assertOk()->getContent());
        $text = preg_replace('/\s+/', '', strip_tags($stage));

        $this->assertStringContainsString('R310.00', $text);
        $this->assertStringContainsString('data-ds-countdown', $stage);
        $this->assertStringContainsString('data-target="'.$soon->fresh()->ends_at->getTimestampMs().'"', $stage);
        $this->assertStringContainsString('data-ds-rail', $stage);
        $this->assertStringContainsString('<dt>Your top bid</dt>', $stage);
        $this->assertStringContainsString('<dt>Bids</dt><dd>2</dd>', $stage);
    }

    public function test_the_needs_rows_carry_a_word_before_the_colour(): void
    {
        [$buyer] = $this->buyerWithTwoBids();
        $other = $this->lot(['title' => 'Lost Lot', 'ends_at' => now()->addHours(5)]);
        $this->bid($other, $buyer, '50.00');
        $this->bid($other, $this->person('Higher'), '60.00');

        $html = $this->actingAs($buyer)->get('/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#dc-need__tag.*?<span>Outbid</span>#s', $html);
    }

    public function test_the_tables_name_their_sortable_columns_and_keep_the_server_order(): void
    {
        [$buyer] = $this->buyerWithTwoBids();

        $html = $this->actingAs($buyer)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<table class="dc-tbl" data-sortable>', $html);
        foreach (['title', 'your', 'now', 'closes'] as $key) {
            $this->assertStringContainsString('data-key="'.$key.'"', $html);
        }
        $this->assertLessThan(strpos($html, 'data-title="Later Lot"'), strpos($html, 'data-title="Soon Lot"'));
    }

    public function test_a_buyer_with_only_watched_lots_sees_the_floors_next_close_on_the_stage(): void
    {
        $user = $this->person('Watcher');
        $watched = $this->lot(['title' => 'Watched Far', 'ends_at' => now()->addDays(5)]);
        Watchlist::create(['user_id' => $user->id, 'auction_id' => $watched->id]);
        $this->lot(['title' => 'Floor Next', 'ends_at' => now()->addDays(2)]);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $stage = $this->stageHtml($html);

        $this->assertStringContainsString('data-stage="floor"', $stage);
        $this->assertStringContainsString('Closing next on the floor', $stage);
        $this->assertStringContainsString('Floor Next', $stage);
        $this->assertStringNotContainsString('id="my-bids"', $html);
    }

    public function test_empty_states_are_honest(): void
    {
        $seller = $this->person('Pieter');
        $this->lot(['status' => 'draft'], $seller);

        $html = $this->actingAs($seller)->get('/dashboard')->assertOk()->getContent();
        $stage = $this->stageHtml($html);

        $this->assertStringContainsString('None of your lots is open for bids', $stage);
        $this->assertStringContainsString('1 draft not published', $stage);
        $this->assertStringNotContainsString('data-ds-countdown', $stage);

        $newcomer = $this->person('Nomsa');
        $new = $this->actingAs($newcomer)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Nothing is live right now', $this->stageHtml($new));
        $this->assertStringContainsString('No lot is open for bids right now.', $new);
    }

    public function test_another_persons_photo_rows_and_bids_never_reach_the_page(): void
    {
        [$buyer] = $this->buyerWithTwoBids();
        $other = $this->person('Naledi');
        $secret = $this->lot(['title' => 'Naledis Secret Lot', 'ends_at' => now()->addHours(6)]);
        $this->bid($secret, $other, '700.00');
        AuctionImage::create(['auction_id' => $secret->id, 'path' => 'x/secret.jpg', 'original_name' => 's.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'width' => 640, 'height' => 480, 'position' => 0]);

        $html = $this->actingAs($buyer)->get('/dashboard?lot='.$secret->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('Naledis Secret Lot', strip_tags($this->stageHtml($html)));
        $this->assertStringNotContainsString('/images/'.$secret->id.'/', $html.'');
    }
}
