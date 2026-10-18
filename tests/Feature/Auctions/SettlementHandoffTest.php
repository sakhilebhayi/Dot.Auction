<?php

namespace Tests\Feature\Auctions;

use App\Events\AuctionSettled;
use App\Listeners\RecordSettlementForPayment;
use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Notifications\AuctionSoldNotification;
use App\Notifications\AuctionWonNotification;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SettlementHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function endedWithWinner(string $amount = '220.00'): array
    {
        $auction = Auction::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10)]);
        $winner = User::factory()->withPersonalTeam()->create();
        app(BidPlacer::class)->place($auction, $winner, $amount);
        $auction->update(['ends_at' => now()->subMinute()]);

        return [$auction, $winner];
    }

    public function test_the_listener_is_queued_and_registered_for_the_settled_event(): void
    {
        $this->assertTrue(is_subclass_of(RecordSettlementForPayment::class, ShouldQueue::class));
        Event::fake();
        Event::assertListening(AuctionSettled::class, RecordSettlementForPayment::class);
    }

    public function test_settling_an_auction_with_a_winner_records_an_awaiting_payment_row(): void
    {
        [$auction, $winner] = $this->endedWithWinner('1234.50');

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $row = AuctionSettlement::sole();
        $this->assertNotEmpty($row->id);
        $this->assertSame($auction->id, $row->auction_id);
        $this->assertSame($winner->id, $row->winner_id);
        $this->assertSame($auction->seller_id, $row->seller_id);
        $this->assertSame(Bid::where('auction_id', $auction->id)->value('id'), $row->winning_bid_id);
        $this->assertSame(123450, $row->amount_cents);
        $this->assertSame('ZAR', $row->currency);
        $this->assertSame('awaiting_payment', $row->status);
    }

    public function test_the_listener_is_dispatched_on_the_queue_not_inline(): void
    {
        Queue::fake();
        [$auction] = $this->endedWithWinner();

        $this->artisan('auction:settle-ended')->assertSuccessful();

        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === RecordSettlementForPayment::class);
        $this->assertSame(0, AuctionSettlement::count(), 'nothing is written until the queue worker runs the listener');
    }

    public function test_replaying_the_event_does_not_duplicate_the_row_or_the_seller_notice(): void
    {
        [$auction, $winner] = $this->endedWithWinner();
        $this->artisan('auction:settle-ended')->assertSuccessful();
        $bid = Bid::where('auction_id', $auction->id)->first();

        event(new AuctionSettled($auction->fresh(), $bid));
        event(new AuctionSettled($auction->fresh(), $bid));

        $this->assertSame(1, AuctionSettlement::count());
        $this->assertSame(1, $auction->seller->notifications()->where('type', AuctionSoldNotification::class)->count());
    }

    public function test_an_unsold_auction_records_nothing(): void
    {
        $auction = Auction::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame(0, AuctionSettlement::count());
    }

    public function test_a_reserve_not_met_auction_records_nothing_until_the_seller_accepts(): void
    {
        $auction = Auction::factory()->create([
            'reserve_price' => '900.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10),
        ]);
        $bidder = User::factory()->create();
        app(BidPlacer::class)->place($auction, $bidder, '220.00');
        $auction->update(['ends_at' => now()->subMinute()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();
        $this->assertSame('pending_review', $auction->fresh()->status);
        $this->assertSame(0, AuctionSettlement::count());

        $proposal = ReserveNotMetProposal::sole();
        Livewire::actingAs($auction->seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept');

        $row = AuctionSettlement::sole();
        $this->assertSame($bidder->id, $row->winner_id);
        $this->assertSame(22000, $row->amount_cents);
        $this->assertSame('awaiting_payment', $row->status);
    }

    public function test_a_buy_now_purchase_records_a_settlement(): void
    {
        $auction = Auction::factory()->create(['buy_now_price' => '500.00']);
        $buyer = User::factory()->create();

        app(BuyNowPurchaser::class)->buy($auction, $buyer);

        $row = AuctionSettlement::sole();
        $this->assertSame($buyer->id, $row->winner_id);
        $this->assertSame(50000, $row->amount_cents);
    }

    public function test_winner_and_seller_are_told_the_payment_step_is_pending(): void
    {
        [$auction, $winner] = $this->endedWithWinner();

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $won = $winner->notifications()->where('type', AuctionWonNotification::class)->sole()->data;
        $sold = $auction->seller->notifications()->where('type', AuctionSoldNotification::class)->sole()->data;
        $this->assertStringContainsString('payment', strtolower($won['message']));
        $this->assertStringContainsString('not yet', strtolower($won['message']));
        $this->assertStringContainsString('payment', strtolower($sold['message']));
        $this->assertStringContainsString('not yet', strtolower($sold['message']));
        $this->assertStringContainsString('R220.00', $sold['message']);
    }

    public function test_no_payment_provider_is_called(): void
    {
        Http::fake();
        [$auction] = $this->endedWithWinner();

        $this->artisan('auction:settle-ended')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_auction_page_tells_the_winner_and_seller_payment_is_pending_and_nobody_else(): void
    {
        [$auction, $winner] = $this->endedWithWinner();
        $this->artisan('auction:settle-ended')->assertSuccessful();
        $bystander = User::factory()->withPersonalTeam()->create();

        $this->actingAs($winner)->get(route('auctions.show', $auction))->assertOk()
            ->assertSee('Payment pending')->assertSee('nothing is due');
        $this->actingAs($auction->seller)->get(route('auctions.show', $auction))->assertOk()
            ->assertSee('Payment pending');
        $this->actingAs($bystander)->get(route('auctions.show', $auction))->assertOk()
            ->assertDontSee('Payment pending');
    }
}
