<?php

namespace Tests\Feature\R42;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\User;
use App\Notifications\AuctionEndingSoonNotification;
use App\Services\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * F-11: storage is UTC, people read and type Africa/Johannesburg (config auction.display_timezone).
 */
class DisplayTimezoneTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 06:00:00'); // 08:00 in Johannesburg
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_storage_stays_utc_and_the_display_zone_defaults_to_johannesburg(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Africa/Johannesburg', config('auction.display_timezone'));
        $this->assertSame('Africa/Johannesburg', DisplayTime::zone());
        $this->assertSame('SAST', DisplayTime::abbreviation());
    }

    public function test_an_unknown_zone_falls_back_instead_of_breaking_pages(): void
    {
        config(['auction.display_timezone' => 'Mars/Olympus']);

        $this->assertSame('Africa/Johannesburg', DisplayTime::zone());
    }

    public function test_a_listing_created_at_nine_local_stores_seven_utc(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $category = AuctionCategory::factory()->create();

        $this->actingAs($seller)->post(route('seller.auctions.store'), [
            'title' => 'Morning lot',
            'category_id' => $category->id,
            'starting_price' => '100.00',
            'bid_increment' => '10.00',
            'starts_at' => '2026-11-10T09:00',
            'ends_at' => '2026-11-12T18:30',
        ])->assertSessionHasNoErrors();

        $auction = Auction::sole();
        $this->assertSame('2026-11-10 07:00:00', $auction->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-12 16:30:00', $auction->getRawOriginal('ends_at'));
    }

    public function test_the_edit_form_shows_the_stored_time_back_in_local_time(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $auction = Auction::factory()->draft()->create([
            'seller_id' => $seller->id,
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-12 16:30:00',
        ]);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();

        $this->assertStringContainsString('value="2026-11-12T18:30"', $html);
        $this->assertStringContainsString('SAST', $html);
    }

    public function test_an_overflowing_or_malformed_input_is_not_a_date(): void
    {
        $this->assertNull(DisplayTime::parseInput('2026-02-31T10:00'));
        $this->assertNull(DisplayTime::parseInput('tomorrow'));
        $this->assertNull(DisplayTime::parseInput(['2026-11-10T09:00']));
        $this->assertSame('2026-11-10 07:00:00', DisplayTime::parseInput('2026-11-10T09:00:00')->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', DisplayTime::parseInput('2026-11-10T09:00')->timezoneName);
    }

    public function test_another_display_zone_is_honoured(): void
    {
        config(['auction.display_timezone' => 'America/New_York']);

        $this->assertSame('2026-11-10 14:00:00', DisplayTime::parseInput('2026-11-10T09:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10T09:00', DisplayTime::inputValue('2026-11-10 14:00:00'));
    }

    public function test_the_helpers_agree_with_each_other(): void
    {
        $at = Carbon::parse('2026-11-08 16:05:09', 'UTC');

        $this->assertSame('8 Nov 2026, 18:05', DisplayTime::format($at));
        $this->assertSame('18:05:09 SAST', DisplayTime::format($at, 'H:i:s', true));
        $this->assertSame('18:05:09 SAST', Auction::localTime($at, 'H:i:s', true));
        $this->assertSame('18:05', $at->localFormat('H:i'));
        $this->assertSame('Africa/Johannesburg', $at->local()->timezoneName);
        $this->assertSame($at->getTimestamp(), $at->local()->getTimestamp());
        $this->assertSame('2026-11-08T18:05:09+02:00', DisplayTime::iso($at));
        $this->assertSame('', DisplayTime::format(null));
        $this->assertNull(DisplayTime::iso(null));
        $this->assertSame('SAST (Africa/Johannesburg)', DisplayTime::label());
    }

    public function test_the_blade_directive_prints_local_time(): void
    {
        $html = Blade::render("@localtime(\$at, 'H:i', true)", ['at' => Carbon::parse('2026-11-08 16:05:00', 'UTC')]);

        $this->assertSame('18:05 SAST', $html);
    }

    public function test_the_extension_message_names_the_local_close_time(): void
    {
        config(['auction.snipe_window_minutes' => 5, 'auction.snipe_extension_minutes' => 5, 'auction.snipe_max_extensions' => 3]);
        $auction = Auction::factory()->create(['ends_at' => now()->addMinutes(2)]); // 06:02 UTC
        $buyer = User::factory()->withPersonalTeam()->create();

        $component = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', '220.00')
            ->call('placeBid');

        $this->assertStringContainsString('Closing time extended to 08:07:00 SAST', $component->get('success'));
    }

    public function test_pages_print_local_times(): void
    {
        // "Pay by" is printed only while payment is actually switched on, so it is on here.
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(settlement: ['payment_due_at' => '2026-11-10 07:00:00']);

        $html = $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertOk()->getContent();

        $this->assertStringContainsString('10 Nov 2026, 09:00 SAST', $html);
        $this->assertStringNotContainsString('UTC', $html);
    }

    public function test_the_ending_soon_event_carries_the_local_offset(): void
    {
        $auction = Auction::factory()->create(['ends_at' => '2026-11-08 07:00:00']);
        $notification = new AuctionEndingSoonNotification($auction);

        $event = $notification->toNotifyEvent($auction->seller);

        $this->assertSame('2026-11-08T09:00:00+02:00', $event['data']['ends_at']);
    }

    /**
     * Guard for the UI work: a view that formats a stored time with ->format() prints UTC. Go through @localtime,
     * Auction::localTime() or ->local().
     */
    public function test_no_view_formats_a_stored_time_in_utc(): void
    {
        $offenders = [];
        $pattern = '/->(?:starts_at|ends_at|paid_at|payment_due_at|created_at|updated_at|cancelled_at|overdue_at|decided_at|expires_at)\??->format\(/';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match($pattern, $line)) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'These views print a stored (UTC) time without converting it: '.implode(', ', $offenders));
    }
}
