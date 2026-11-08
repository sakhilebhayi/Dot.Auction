<?php

namespace Tests\Feature\Audit;

use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use App\Services\Auctions\AuctionSettler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Settling an auction commits first and announces afterwards. The announcement that creates the payment
 * settlement used to run AFTER the winner's notice, so a notice that threw left an ended auction with a winner
 * and no settlement (nothing re-announces it): the winner could never pay and the seller was never told.
 */
class SettlementResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function failWinnerNotices(): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            if ($event->notification instanceof AuctionWonNotification) {
                throw new RuntimeException('the notice could not be delivered');
            }
        });
    }

    private function endedAuctionWithWinner(array $overrides = []): array
    {
        $auction = Auction::factory()->create(array_merge([
            'starting_price' => '100.00', 'current_price' => '150.00', 'bid_increment' => '10.00',
            'starts_at' => now()->subDay(), 'ends_at' => now()->subMinute(),
        ], $overrides));
        $winner = User::factory()->create();
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $winner->id, 'amount' => '150.00', 'is_winning' => true]);

        return [$auction, $winner, $bid];
    }

    public function test_a_winner_notice_that_fails_does_not_stop_the_settlement_being_recorded(): void
    {
        [$auction, $winner] = $this->endedAuctionWithWinner();
        $this->failWinnerNotices();

        $this->assertTrue(app(AuctionSettler::class)->settle($auction));

        $this->assertSame('ended', $auction->fresh()->status);
        $settlement = AuctionSettlement::where('auction_id', $auction->id)->first();
        $this->assertNotNull($settlement, 'the settlement must exist even though the notice failed');
        $this->assertSame($winner->id, $settlement->winner_id);
        $this->assertSame(15000, $settlement->amount_cents);
    }

    public function test_settling_twice_records_one_settlement_and_one_seller_notice(): void
    {
        [$auction] = $this->endedAuctionWithWinner();

        $this->assertTrue(app(AuctionSettler::class)->settle($auction));
        $this->assertFalse(app(AuctionSettler::class)->settle($auction));
        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->count());
        $this->assertSame(1, $auction->seller->notifications()->where('type', 'like', '%AuctionSold%')->count());
    }

    public function test_accepting_a_reserve_not_met_bid_records_the_settlement_even_if_the_notice_fails(): void
    {
        [$auction, $winner, $bid] = $this->endedAuctionWithWinner(['reserve_price' => '500.00']);
        $this->assertTrue(app(AuctionSettler::class)->settle($auction));
        $proposal = ReserveNotMetProposal::where('auction_id', $auction->id)->firstOrFail();
        $this->assertSame('pending_review', $auction->fresh()->status);
        $this->assertSame(0, AuctionSettlement::count());

        $this->failWinnerNotices();

        Livewire::actingAs($auction->seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept');

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame($winner->id, AuctionSettlement::where('auction_id', $auction->id)->value('winner_id'));
    }

    public function test_rejecting_a_reserve_not_met_bid_ends_the_auction_without_a_settlement(): void
    {
        [$auction] = $this->endedAuctionWithWinner(['reserve_price' => '500.00']);
        app(AuctionSettler::class)->settle($auction);
        $proposal = ReserveNotMetProposal::where('auction_id', $auction->id)->firstOrFail();

        Livewire::actingAs($auction->seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('reject');

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame('rejected', $proposal->fresh()->status);
        $this->assertSame(0, AuctionSettlement::count());
    }

    public function test_only_the_seller_can_decide_and_a_decision_cannot_be_made_twice(): void
    {
        [$auction] = $this->endedAuctionWithWinner(['reserve_price' => '500.00']);
        app(AuctionSettler::class)->settle($auction);
        $proposal = ReserveNotMetProposal::where('auction_id', $auction->id)->firstOrFail();

        Livewire::actingAs(User::factory()->create())
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept')
            ->assertForbidden();
        $this->assertSame('pending', $proposal->fresh()->status);

        $panel = Livewire::actingAs($auction->seller)->test(ReserveDecisionPanel::class, ['proposal' => $proposal]);
        $panel->call('accept');
        $panel->call('reject');

        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame(1, AuctionSettlement::count());
    }
}
