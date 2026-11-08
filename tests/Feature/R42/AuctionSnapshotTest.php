<?php

namespace Tests\Feature\R42;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\User;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\AuctionSnapshot;
use App\Services\Auctions\BidPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F-04 (backend half): BidPanel::snapshot() is what a polling client and a client-side countdown read.
 */
class AuctionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function snapshot(Auction $auction, ?User $viewer = null): array
    {
        return app(AuctionSnapshot::class)->for($auction, $viewer);
    }

    public function test_it_carries_the_contract_fields(): void
    {
        $auction = Auction::factory()->create(['ends_at' => now()->addMinutes(90)]);
        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');
        AuctionSnapshot::forget($auction->id);

        $snap = $this->snapshot($auction->fresh());

        $this->assertSame($auction->id, $snap['auction_id']);
        $this->assertSame('active', $snap['status']);
        $this->assertSame('220.00', $snap['current_price']);
        $this->assertSame(22000, $snap['current_price_cents']);
        $this->assertSame('240.00', $snap['minimum_bid']);
        $this->assertSame(1, $snap['bid_count']);
        $this->assertSame('2026-11-08T13:30:00Z', $snap['ends_at']);
        $this->assertSame(5400, $snap['seconds_remaining']);
        $this->assertSame('2026-11-08T12:00:00Z', $snap['server_now']);
        $this->assertSame(now()->getTimestamp() * 1000, $snap['server_now_ms']);
        $this->assertSame(10, $snap['poll_seconds']);
        $this->assertFalse($snap['leading_bidder_is_me']);
    }

    public function test_it_says_whether_the_viewer_is_the_leader(): void
    {
        $auction = Auction::factory()->create();
        $leader = User::factory()->create();
        $other = User::factory()->create();
        app(BidPlacer::class)->place($auction, $leader, '220.00');

        $this->assertTrue($this->snapshot($auction, $leader)['leading_bidder_is_me']);
        $this->assertFalse($this->snapshot($auction, $other)['leading_bidder_is_me']);
        $this->assertFalse($this->snapshot($auction, null)['leading_bidder_is_me']);
    }

    public function test_a_poll_costs_one_query_and_the_next_second_costs_none(): void
    {
        $auction = Auction::factory()->create();
        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');
        AuctionSnapshot::forget($auction->id);

        DB::enableQueryLog();
        $this->snapshot($auction);
        $this->assertCount(1, DB::getQueryLog(), 'the first poll should be a single SELECT');

        DB::flushQueryLog();
        $this->snapshot($auction, User::factory()->make(['id' => 5]));
        $this->snapshot($auction);
        $this->assertCount(0, DB::getQueryLog(), 'polls inside the same second are served from the cache');
    }

    public function test_a_new_bid_is_visible_at_once_not_after_the_cache_expires(): void
    {
        $auction = Auction::factory()->create();
        $this->assertSame(0, $this->snapshot($auction)['bid_count']);

        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');

        $this->assertSame(1, $this->snapshot($auction)['bid_count']);
        $this->assertSame('220.00', $this->snapshot($auction)['current_price']);
    }

    public function test_the_status_follows_the_server_clock_even_from_a_cached_row(): void
    {
        $auction = Auction::factory()->create(['ends_at' => now()->addSeconds(30)]);

        $this->assertSame('active', $this->snapshot($auction)['status']);

        Carbon::setTestNow(now()->addSeconds(31));

        $late = $this->snapshot($auction);
        $this->assertSame('ended', $late['status'], 'closed by the clock before the settlement job has run');
        $this->assertNull($late['seconds_remaining']);
        $this->assertSame('2026-11-08T12:00:31Z', $late['server_now']);
    }

    public function test_scheduled_pending_and_settled_states(): void
    {
        $scheduled = Auction::factory()->scheduled()->create();
        $this->assertSame('scheduled', $this->snapshot($scheduled)['status']);

        $auction = Auction::factory()->create(['reserve_price' => '500.00', 'ends_at' => now()->addMinute()]);
        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');
        Carbon::setTestNow(now()->addMinutes(10)); // past the anti-snipe extension too
        app(AuctionSettler::class)->settle($auction->id);

        $this->assertSame('pending_review', $this->snapshot($auction)['status']);
    }

    public function test_a_missing_auction_is_reported_not_thrown(): void
    {
        $this->assertSame('missing', app(AuctionSnapshot::class)->for(987654)['status']);
    }

    public function test_the_snapshot_never_exposes_the_reserve_price(): void
    {
        $auction = Auction::factory()->create(['reserve_price' => '777.00']);

        $this->assertStringNotContainsString('777', json_encode($this->snapshot($auction)));
    }

    public function test_the_bid_panel_method_returns_the_snapshot_for_the_signed_in_user(): void
    {
        $auction = Auction::factory()->create();
        $buyer = User::factory()->withPersonalTeam()->create();

        $panel = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', '220.00')
            ->call('placeBid');

        $snap = $panel->call('snapshot')->effects['returns'][0] ?? null;

        $this->assertNotNull($snap);
        $this->assertSame('220.00', $snap['current_price']);
        $this->assertTrue($snap['leading_bidder_is_me']);
        $this->assertSame('240.00', $snap['minimum_bid']);
    }
}
