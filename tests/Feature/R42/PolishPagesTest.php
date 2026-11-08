<?php

namespace Tests\Feature\R42;

use App\Livewire\Auctions\BidPanel;
use App\Models\AuctionItem;
use App\Support\Display;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/** Design jury 1, P1 and P2: what is said and how it is labelled on the lot page, the profile, dates and the closing row. */
class PolishPagesTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_the_leader_gets_one_short_line_and_the_ladder_still_shows_the_next_valid_bid(): void
    {
        $lot = $this->lot(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $me = $this->person('Me');
        $this->bid($lot, $me, '110.00');

        $html = $this->actingAs($me)->get(route('auctions.show', $lot))->assertOk()->getContent();

        $this->assertStringContainsString('We will tell you if you are outbid.', $html);
        $this->assertStringNotContainsString('bidding higher against yourself', $html, 'the heavy paragraph is gone');
        $this->assertStringNotContainsString('data-ds-bid-input', $html, 'no form that invites raising your own lead');
        $this->assertStringContainsString('Next bid', $html, 'the minimum next bid stays visible on the ladder');
    }

    public function test_ladder_rungs_are_named_with_their_amount_and_the_dialog_names_the_lot(): void
    {
        $lot = $this->lot(['title' => 'Mountain bike', 'starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $me = $this->person('Me');
        $other = $this->person('Other');
        $this->bid($lot, $other, '110.00');

        $page = $this->actingAs($me)->get(route('auctions.show', $lot))->assertOk();
        $html = $page->getContent();
        preg_match_all('~<button type="button" class="ds-rung__row"[^>]*>(.*?)</button>~s', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $inner) {
            $name = preg_replace('/\s+/', ' ', trim(strip_tags($inner)));
            $this->assertMatchesRegularExpression('/^(Next bid|Bid) R[\d,]+\.\d{2}$/', $name, 'a rung reads as what it does');
        }

        Livewire::actingAs($me)->test(BidPanel::class, ['auction' => $lot])
            ->set('bidAmount', '120.00')->call('review')
            ->assertSee('Place a bid of R120.00?')->assertSee('On Mountain bike');
    }

    public function test_the_included_items_are_labelled_as_what_is_included(): void
    {
        $lot = $this->lot(['title' => 'Bundle']);
        AuctionItem::forceCreate(['auction_id' => $lot->id, 'name' => 'Mountain bike', 'condition' => 'used', 'location' => 'Cape Town']);

        $this->actingAs($this->person('V'))->get(route('auctions.show', $lot))->assertOk()->assertSee('What is included')->assertDontSee('In this lot');
    }

    public function test_the_watch_button_sits_beside_the_status_chips_and_does_not_wrap_under_them(): void
    {
        $lot = $this->lot(['ends_at' => now()->addMinutes(10)]);
        $html = $this->actingAs($this->person('V'))->get(route('auctions.show', $lot))->getContent();

        $chips = strpos($html, 'lot-bid__chips');
        $watch = strpos($html, 'class="lot-watch"');
        $this->assertNotFalse($chips);
        $this->assertGreaterThan($chips, $watch, 'the chips come first and the watch button is its own item beside them, not inside the wrapping chip list');
    }

    public function test_profile_and_team_pages_speak_in_sentence_case_without_a_photo_promise(): void
    {
        $me = $this->person('Me');
        $html = $this->visible($this->actingAs($me)->get(route('profile.show'))->assertOk()->getContent());

        foreach (['Your details', 'Password', 'Two-step sign-in', 'Where you are signed in', 'Sign out other devices', 'Delete account', 'Turn on'] as $word) {
            $this->assertStringContainsString($word, $html);
        }
        foreach (['Update your name and photo', 'Two Factor Authentication', 'Browser Sessions', 'Log Out Other Browser Sessions', 'Delete Account', 'Update Password', 'Profile Information', 'Google Authenticator'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "$gone is gone");
        }
        $this->assertStringContainsString('Former member', $html, 'delete-account copy says what really happens');

        $team = $this->visible($this->actingAs($me)->get(route('teams.show', $me->currentTeam->id))->assertOk()->getContent());
        foreach (['Team Name', 'Team Members', 'Add Team Member', 'Delete Team'] as $gone) {
            $this->assertStringNotContainsString($gone, $team, "$gone is gone");
        }
    }

    public function test_every_date_with_a_time_uses_one_pattern_and_the_year_only_when_it_differs(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 10:00:00', 'UTC'));
        $thisYear = Carbon::parse('2026-11-09 13:59:00', 'UTC');
        $other = Carbon::parse('2027-01-04 08:00:00', 'UTC');

        foreach (['j M Y, H:i', 'j M, H:i', 'D j M, H:i', null] as $format) {
            $this->assertSame('Mon 9 Nov, 15:59', Display::time($thisYear, $format), 'format '.var_export($format, true));
        }
        $this->assertSame('Mon 4 Jan 2027, 10:00', Display::time($other, 'j M, H:i'));
        $this->assertSame('Mon 9 Nov, 15:59 SAST', Display::time($thisYear, null, true));
        $this->assertSame('15:59:00', Display::time($thisYear, 'H:i:s'), 'a clock is printed as asked');
    }

    public function test_a_cancelled_lot_shows_when_it_was_cancelled_not_its_planned_close(): void
    {
        $seller = $this->person('Seller');
        $lot = $this->lot(['status' => 'cancelled', 'title' => 'Garden tool set', 'ends_at' => now()->addDays(2)], $seller);
        $lot->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $html = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));

        $this->assertStringContainsString('Cancelled', $text);
        $this->assertStringNotContainsString(Display::time(now()->addDays(2)).' Closed', $text);
    }

    /** The words a person reads: no comments, no markup, entities decoded. */
    private function visible(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~s', '', $html))));
    }
}
