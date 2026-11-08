<?php

namespace Tests\Feature\Console;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Notifications\AuctionSoldNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * F-06: the sweep that creates a settlement for an ended auction whose queued hand-off was lost.
 */
class RepairUnsettledAuctionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An ended auction with a winning bid and, deliberately, no settlement.
     *
     * @return array{0: Auction, 1: User, 2: Bid}
     */
    private function unsettled(array $overrides = []): array
    {
        $auction = Auction::factory()->create(array_merge([
            'status' => 'ended',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(),
            'current_price' => '150.00',
        ], $overrides));
        $winner = User::factory()->create();
        $bid = Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => $winner->id, 'amount' => '150.00', 'is_winning' => true]);

        return [$auction, $winner, $bid];
    }

    public function test_it_creates_the_missing_settlement_once_and_logs_at_error_level(): void
    {
        Log::spy();
        Notification::fake();
        [$auction, $winner] = $this->unsettled();

        $this->artisan('auction:repair-unsettled')->assertSuccessful();

        $settlement = AuctionSettlement::sole();
        $this->assertSame($auction->id, $settlement->auction_id);
        $this->assertSame($winner->id, $settlement->winner_id);
        $this->assertSame(15000, $settlement->amount_cents);
        $this->assertSame('awaiting_payment', $settlement->status);
        Notification::assertSentToTimes($auction->seller, AuctionSoldNotification::class, 1);
        Log::shouldHaveReceived('error')->once()->with('Repaired an ended auction that had a winner but no settlement.', ['auction_id' => $auction->id]);
    }

    public function test_repeated_runs_change_nothing_and_stay_quiet(): void
    {
        Log::spy();
        Notification::fake();
        [$auction] = $this->unsettled();

        $this->artisan('auction:repair-unsettled')->assertSuccessful();
        $this->artisan('auction:repair-unsettled')->assertSuccessful();
        $this->artisan('auction:repair-unsettled')->assertSuccessful();

        $this->assertSame(1, AuctionSettlement::count());
        Notification::assertSentToTimes($auction->seller, AuctionSoldNotification::class, 1);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_it_leaves_auctions_that_are_fine_or_not_its_business_alone(): void
    {
        Notification::fake();

        // Settled properly already.
        [$settled, $winner, $bid] = $this->unsettled();
        AuctionSettlement::unguarded(fn () => AuctionSettlement::create([
            'auction_id' => $settled->id, 'winning_bid_id' => $bid->id, 'seller_id' => $settled->seller_id,
            'winner_id' => $winner->id, 'amount_cents' => 15000, 'currency' => 'ZAR', 'status' => 'awaiting_payment',
        ]));

        // Closed a minute ago: the normal hand-off may still be on its way.
        $this->unsettled(['ends_at' => now()->subMinute()]);

        // Older than the look-back: a sale that pre-dates online settlement is never back-filled.
        $this->unsettled(['ends_at' => now()->subDays(30), 'starts_at' => now()->subDays(31)]);

        // Still running, awaiting the seller's reserve decision, or cancelled.
        $this->unsettled(['status' => 'active', 'ends_at' => now()->addHour()]);
        $this->unsettled(['status' => 'pending_review']);
        $this->unsettled(['status' => 'cancelled']);

        // The seller rejected the high bid: no sale, so no settlement.
        [$rejected, , $rejectedBid] = $this->unsettled(['reserve_price' => '500.00']);
        ReserveNotMetProposal::forceCreate(['auction_id' => $rejected->id, 'bid_id' => $rejectedBid->id, 'reserve_price' => '500.00', 'status' => 'rejected']);

        // Ended with no bids at all.
        Auction::factory()->ended()->create();

        $this->artisan('auction:repair-unsettled')->assertSuccessful();

        $this->assertSame(1, AuctionSettlement::count(), 'only the pre-existing settlement');
        Notification::assertNothingSent();
    }

    public function test_an_accepted_reserve_proposal_that_lost_its_settlement_is_repaired(): void
    {
        Notification::fake();
        [$auction, , $bid] = $this->unsettled(['reserve_price' => '500.00']);
        ReserveNotMetProposal::forceCreate(['auction_id' => $auction->id, 'bid_id' => $bid->id, 'reserve_price' => '500.00', 'status' => 'accepted']);

        $this->artisan('auction:repair-unsettled')->assertSuccessful();

        $this->assertSame($auction->id, AuctionSettlement::sole()->auction_id);
    }

    public function test_it_is_scheduled_hourly_without_a_long_lock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'auction:repair-unsettled'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(15, $event->expiresAt);
    }
}
