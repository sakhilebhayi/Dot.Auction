<?php

namespace Tests\Feature\Auctions;

use App\Events\BidPlaced;
use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\User;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Time is frozen so "exactly N minutes left" is exact, not approximate.
 */
class AntiSnipeTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-01 12:00:00');
        Carbon::setTestNow($this->now);
        config([
            'auction.snipe_window_minutes' => 5,
            'auction.snipe_extension_minutes' => 5,
            'auction.snipe_max_extensions' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auctionEndingIn(int|float $seconds): Auction
    {
        return Auction::factory()->create(['ends_at' => $this->now->copy()->addSeconds((int) $seconds)]);
    }

    private function bid(Auction|int $auction, string $amount): void
    {
        app(BidPlacer::class)->place($auction, User::factory()->create(), $amount);
    }

    public function test_the_defaults_are_five_and_five(): void
    {
        $config = require base_path('config/auction.php');

        $this->assertSame(5, $config['snipe_window_minutes']);
        $this->assertSame(5, $config['snipe_extension_minutes']);
        $this->assertGreaterThan(0, $config['snipe_max_extensions']);
    }

    public function test_a_bid_inside_the_window_extends_the_close(): void
    {
        $auction = $this->auctionEndingIn(120);

        $this->bid($auction, '220.00');

        $auction->refresh();
        $this->assertTrue($auction->ends_at->equalTo($this->now->copy()->addSeconds(120 + 300)));
        $this->assertSame(1, $auction->snipe_extensions);
    }

    public function test_a_bid_exactly_on_the_window_boundary_extends(): void
    {
        $auction = $this->auctionEndingIn(300);

        $this->bid($auction, '220.00');

        $this->assertSame(1, $auction->fresh()->snipe_extensions);
        $this->assertTrue($auction->fresh()->ends_at->equalTo($this->now->copy()->addSeconds(600)));
    }

    public function test_a_bid_one_second_outside_the_window_does_not_extend(): void
    {
        $auction = $this->auctionEndingIn(301);

        $this->bid($auction, '220.00');

        $this->assertSame(0, $auction->fresh()->snipe_extensions);
        $this->assertTrue($auction->fresh()->ends_at->equalTo($this->now->copy()->addSeconds(301)));
    }

    public function test_the_decision_uses_the_locked_row_not_a_stale_model(): void
    {
        $auction = $this->auctionEndingIn(120);
        $stale = Auction::find($auction->id); // believes 2 minutes remain

        $this->bid($auction, '220.00');          // extends the real close to 7 minutes away
        $this->bid($stale, '240.00');            // 7 minutes remain: outside the window

        $fresh = $auction->fresh();
        $this->assertSame(1, $fresh->snipe_extensions, 'the stale copy must not trigger a second extension');
        $this->assertTrue($fresh->ends_at->equalTo($this->now->copy()->addSeconds(420)));
    }

    public function test_a_stale_model_that_thinks_it_is_outside_the_window_still_extends_when_it_is_inside(): void
    {
        $auction = $this->auctionEndingIn(600);
        $stale = Auction::find($auction->id);
        $auction->update(['ends_at' => $this->now->copy()->addSeconds(60)]); // seller-side change, e.g. earlier edit

        $this->bid($stale, '220.00');

        $this->assertSame(1, $auction->fresh()->snipe_extensions);
    }

    public function test_extensions_never_go_past_the_configured_maximum(): void
    {
        config(['auction.snipe_max_extensions' => 2]);
        $auction = $this->auctionEndingIn(60);

        $this->bid($auction, '220.00');   // ends +360s, 1
        Carbon::setTestNow($this->now->copy()->addSeconds(100)); // 260s left, inside window
        $this->bid($auction, '240.00');   // ends +660s, 2
        Carbon::setTestNow($this->now->copy()->addSeconds(400)); // 260s left, inside window
        $this->bid($auction, '260.00');   // cap reached: unchanged

        $fresh = $auction->fresh();
        $this->assertSame(2, $fresh->snipe_extensions);
        $this->assertTrue($fresh->ends_at->equalTo($this->now->copy()->addSeconds(60 + 300 + 300)));
        $this->assertCount(3, $fresh->bids, 'the capped bid is still accepted');
    }

    public function test_a_zero_window_disables_anti_snipe(): void
    {
        config(['auction.snipe_window_minutes' => 0]);
        $auction = $this->auctionEndingIn(30);

        $this->bid($auction, '220.00');

        $this->assertSame(0, $auction->fresh()->snipe_extensions);
        $this->assertTrue($auction->fresh()->ends_at->equalTo($this->now->copy()->addSeconds(30)));
    }

    public function test_a_zero_maximum_disables_extensions(): void
    {
        config(['auction.snipe_max_extensions' => 0]);
        $auction = $this->auctionEndingIn(30);

        $this->bid($auction, '220.00');

        $this->assertSame(0, $auction->fresh()->snipe_extensions);
    }

    public function test_a_rejected_bid_never_extends(): void
    {
        $auction = $this->auctionEndingIn(30);

        try {
            $this->bid($auction, '210.00'); // below the 220 minimum
            $this->fail('expected a rejection');
        } catch (BidRejected) {
        }

        $this->assertSame(0, $auction->fresh()->snipe_extensions);
        $this->assertTrue($auction->fresh()->ends_at->equalTo($this->now->copy()->addSeconds(30)));
    }

    public function test_a_bid_after_the_close_is_still_rejected_and_does_not_extend(): void
    {
        $auction = $this->auctionEndingIn(30);
        Carbon::setTestNow($this->now->copy()->addSeconds(31));

        $this->expectException(BidRejected::class);
        try {
            $this->bid($auction, '220.00');
        } finally {
            $this->assertSame(0, $auction->fresh()->snipe_extensions);
        }
    }

    public function test_the_bid_and_the_new_close_commit_together(): void
    {
        $auction = $this->auctionEndingIn(30);

        $this->bid($auction, '220.00');

        $fresh = $auction->fresh();
        $this->assertSame(22000, Auction::toCents($fresh->current_price));
        $this->assertSame(1, $fresh->snipe_extensions);
        $this->assertSame(1, $fresh->bids()->count());
    }

    public function test_the_new_end_time_is_broadcast_with_the_bid(): void
    {
        Event::fake([BidPlaced::class]);
        $auction = $this->auctionEndingIn(120);

        $this->bid($auction, '220.00');

        Event::assertDispatched(BidPlaced::class, function (BidPlaced $event) {
            $payload = $event->broadcastWith();

            return $payload['ends_at'] === $this->now->copy()->addSeconds(420)->toIso8601String()
                && $payload['snipe_extensions'] === 1;
        });
    }

    public function test_the_bid_panel_tells_the_bidder_the_close_moved(): void
    {
        $auction = $this->auctionEndingIn(120);
        $bidder = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($bidder)
            ->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 220)
            ->call('placeBid')
            ->assertSee('Closing time extended')
            ->assertSee('extended 1 time');
    }
}
